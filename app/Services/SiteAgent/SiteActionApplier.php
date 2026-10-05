<?php

namespace App\Services\SiteAgent;

use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Services\Agent\McpClient;
use Illuminate\Support\Str;

/**
 * Carries out the management changes the assistant proposed and the owner
 * approved: orders, subscriptions, posts, users, coupons, new products.
 *
 * The same discipline as the page and product changes in SiteChangeApplier.
 * The target is re-checked at execution — an order is moved only if it is
 * still in the status the owner was shown, a post is changed only if it still
 * reads as it did — because between the preview and the "כן" somebody else may
 * have moved it, and the owner approved a change to what they SAW.
 *
 * The undo is as honest as the change. Where the previous state can be put back
 * without erasing anybody else's work, it is kept on `restore`; where it
 * cannot — a note already emailed, a user already invited, a subscription
 * cancelled for good — `restore` stays null and the owner was told so in the
 * preview, before they agreed.
 *
 * Every result has the shape SiteChangeApplier returns, plus an optional
 * `done` line the conversation adds to its confirmation.
 */
class SiteActionApplier
{
    public function __construct(private McpClient $mcp) {}

    /**
     * @return array{ok: bool, reason: string|null, message: string|null, restore: array<string, mixed>|null, done?: string}
     */
    public function apply(Site $site, SiteAgentRequest $request): array
    {
        $plan = (array) $request->plan;

        try {
            return match ($request->operation) {
                SiteAgentRequest::OP_ORDER_STATUS => $this->orderStatus($site, $plan),
                SiteAgentRequest::OP_ORDER_NOTE => $this->orderNote($site, $plan),
                SiteAgentRequest::OP_SUBSCRIPTION_STATUS => $this->subscriptionStatus($site, $plan),
                SiteAgentRequest::OP_POST_CREATE => $this->postCreate($site, $plan),
                SiteAgentRequest::OP_POST_UPDATE => $this->postUpdate($site, $plan),
                SiteAgentRequest::OP_USER_CREATE => $this->userCreate($site, $plan),
                SiteAgentRequest::OP_USER_ROLE => $this->userRole($site, $plan),
                SiteAgentRequest::OP_COUPON => $this->coupon($site, $plan),
                SiteAgentRequest::OP_PRODUCT_CREATE => $this->productCreate($site, $plan),
                default => $this->refuse('פעולה לא מוכרת.'),
            };
        } catch (\Throwable $e) {
            return $this->failure(Str::limit($e->getMessage(), 200));
        }
    }

    /** Does a `restore` of this kind belong here? */
    public function reverts(string $kind): bool
    {
        return in_array($kind, ['order_status', 'subscription_status', 'created_post', 'post', 'user_role', 'coupon'], true);
    }

    /**
     * @param  array<string, mixed>  $restore
     * @return array{ok: bool, reason: string|null, message: string|null}
     */
    public function revert(Site $site, array $restore): array
    {
        try {
            return match ((string) ($restore['kind'] ?? '')) {
                'order_status' => $this->revertOrderStatus($site, $restore),
                'subscription_status' => $this->revertSubscriptionStatus($site, $restore),
                'created_post' => $this->revertCreatedPost($site, $restore),
                'post' => $this->revertPost($site, $restore),
                'user_role' => $this->revertUserRole($site, $restore),
                'coupon' => $this->revertCoupon($site, $restore),
                default => $this->refuse('אין לי גיבוי לשחזור הבקשה הזו.'),
            };
        } catch (\Throwable $e) {
            return $this->failure(Str::limit($e->getMessage(), 200));
        }
    }

    // --- Orders --------------------------------------------------------------

    /** @param array<string, mixed> $plan */
    private function orderStatus(Site $site, array $plan): array
    {
        $from = (string) $plan['from'];
        $to = (string) $plan['to'];

        $result = $this->call($site, 'wc_order_status_set', array_filter([
            'internal_id' => (int) $plan['order_id'],
            'status' => $to,
            // Moved only if it is still where the owner saw it. The plugin
            // checks and writes in one request, so nothing slips in between.
            'expected_status' => $from,
            'note' => (string) ($plan['note'] ?? ''),
        ], fn ($value): bool => $value !== ''));

        if (($result['changed'] ?? false) !== true) {
            return $this->refuse('ההזמנה כבר אינה בסטטוס '.$this->orderLabel($from)
                .' (עכשיו: '.$this->orderLabel((string) ($result['status'] ?? '')).'), ולכן לא שיניתי אותה.');
        }

        // An undo needs a status the bot may set. Coming from `failed`, say,
        // there is no honest way back from here, and the owner is not offered one.
        $restorable = in_array($from, SiteActionProposer::ORDER_TARGETS, true);

        return $this->ok($restorable ? [
            'kind' => 'order_status',
            'order_id' => (int) $plan['order_id'],
            'status' => $from,
            'after' => (string) ($result['status'] ?? $to),
        ] : null, $to === 'completed' ? 'הלקוח קיבל מ-WooCommerce אימייל שההזמנה הושלמה.' : null);
    }

    /** @param array<string, mixed> $restore */
    private function revertOrderStatus(Site $site, array $restore): array
    {
        $result = $this->call($site, 'wc_order_status_set', [
            'internal_id' => (int) $restore['order_id'],
            'status' => (string) $restore['status'],
            'expected_status' => (string) $restore['after'],
        ]);

        return ($result['changed'] ?? false) === true
            ? $this->ok(null)
            : $this->refuse(SiteChangeApplier::STALE);
    }

    /** @param array<string, mixed> $plan */
    private function orderNote(Site $site, array $plan): array
    {
        $this->call($site, 'wc_order_note_add', [
            'internal_id' => (int) $plan['order_id'],
            'note' => (string) $plan['note'],
            'customer_note' => (bool) ($plan['to_customer'] ?? false),
        ]);

        return $this->ok(null);
    }

    // --- Subscriptions -------------------------------------------------------

    /** @param array<string, mixed> $plan */
    private function subscriptionStatus(Site $site, array $plan): array
    {
        $from = (string) $plan['from'];
        $to = (string) $plan['to'];

        $result = $this->call($site, 'wcs_subscription_status_set', [
            'subscription_id' => (int) $plan['subscription_id'],
            'status' => $to,
            'expected_status' => $from,
        ]);

        if (($result['changed'] ?? false) !== true) {
            return $this->refuse('המנוי כבר אינו במצב שהוצג לכם (עכשיו: '.(string) ($result['status'] ?? '?').'), ולכן לא שיניתי אותו.');
        }

        // A cancellation is final in WooCommerce Subscriptions itself.
        $restorable = $to !== 'cancelled' && in_array($from, ['active', 'on-hold', 'pending-cancel'], true);

        return $this->ok($restorable ? [
            'kind' => 'subscription_status',
            'subscription_id' => (int) $plan['subscription_id'],
            'status' => $from,
            'after' => (string) ($result['status'] ?? $to),
        ] : null);
    }

    /** @param array<string, mixed> $restore */
    private function revertSubscriptionStatus(Site $site, array $restore): array
    {
        $result = $this->call($site, 'wcs_subscription_status_set', [
            'subscription_id' => (int) $restore['subscription_id'],
            'status' => (string) $restore['status'],
            'expected_status' => (string) $restore['after'],
        ]);

        return ($result['changed'] ?? false) === true
            ? $this->ok(null)
            : $this->refuse(SiteChangeApplier::STALE);
    }

    // --- Content -------------------------------------------------------------

    /** @param array<string, mixed> $plan */
    private function postCreate(Site $site, array $plan): array
    {
        $created = $this->call($site, 'wp_content_create', (array) $plan['fields']);
        $id = (int) ($created['created_id'] ?? 0);

        if ($id <= 0) {
            return $this->failure('wp_content_create did not return an id');
        }

        SiteChangePlanner::forget($site);

        // What it looks like as the site stored it — the undo moves it to the
        // trash only while it still looks like this, so a post the owner went
        // on to edit in wp-admin is never thrown away.
        $after = $this->post($site, $id);

        return $this->ok($after === null ? null : [
            'kind' => 'created_post',
            'post_id' => $id,
            'after' => ['title' => $after['title'], 'content' => $after['content']],
        ], isset($created['url']) ? 'קישור: '.$created['url'] : null);
    }

    /** @param array<string, mixed> $restore */
    private function revertCreatedPost(Site $site, array $restore): array
    {
        $id = (int) $restore['post_id'];
        $live = $this->post($site, $id);

        if ($live === null) {
            return $this->refuse('הפריט כבר אינו קיים באתר.');
        }

        if (! $this->sameFields($live, (array) $restore['after'])) {
            return $this->refuse(SiteChangeApplier::STALE);
        }

        // Trash, never delete: it can still be taken back out from wp-admin.
        $this->call($site, 'wp_content_trash', ['id' => $id]);
        SiteChangePlanner::forget($site);

        return $this->ok(null);
    }

    /** @param array<string, mixed> $plan */
    private function postUpdate(Site $site, array $plan): array
    {
        $id = (int) $plan['post_id'];
        $fields = (array) $plan['fields'];
        $live = $this->post($site, $id);

        if ($live === null) {
            return $this->refuse('לא הצלחתי לקרוא את הפריט באתר.');
        }

        if (! $this->sameFields($live, (array) ($plan['current'] ?? []))) {
            return $this->refuse(SiteChangeApplier::STALE);
        }

        $result = $this->call($site, 'wp_content_update', ['id' => $id, ...$fields]);
        $previous = array_intersect_key((array) ($result['previous'] ?? []), $fields);

        SiteChangePlanner::forget($site);

        $after = $this->post($site, $id);

        return $this->ok($previous === [] || $after === null ? null : [
            'kind' => 'post',
            'post_id' => $id,
            'fields' => $previous,
            'after' => array_intersect_key($after, $fields),
        ]);
    }

    /** @param array<string, mixed> $restore */
    private function revertPost(Site $site, array $restore): array
    {
        $id = (int) $restore['post_id'];
        $live = $this->post($site, $id);

        if ($live === null) {
            return $this->refuse('לא הצלחתי לקרוא את הפריט באתר.');
        }

        if (! $this->sameFields($live, (array) $restore['after'])) {
            return $this->refuse(SiteChangeApplier::STALE);
        }

        $this->call($site, 'wp_content_update', ['id' => $id, ...(array) $restore['fields']]);
        SiteChangePlanner::forget($site);

        return $this->ok(null);
    }

    // --- Users ---------------------------------------------------------------

    /** @param array<string, mixed> $plan */
    private function userCreate(Site $site, array $plan): array
    {
        $created = $this->call($site, 'wp_user_create', (array) $plan['fields']);

        return $this->ok(null, ($created['notified'] ?? false) === true
            ? 'נשלח אליו אימייל לקביעת סיסמה.'
            : null);
    }

    /** @param array<string, mixed> $plan */
    private function userRole(Site $site, array $plan): array
    {
        $userId = (int) $plan['user_id'];
        $from = (string) $plan['from'];

        // Still the role the owner was shown — "no role at all" included, which
        // is what a registration waiting for approval usually looks like.
        if ($this->rolesOf($site, $userId, (string) $plan['email']) !== ($from === '' ? [] : [$from])) {
            return $this->refuse(SiteChangeApplier::STALE);
        }

        $result = $this->call($site, 'wp_user_role_set', ['user_id' => $userId, 'role' => (string) $plan['to']]);

        if (($result['changed'] ?? false) !== true) {
            return $this->refuse(SiteChangeApplier::STALE);
        }

        return $this->ok([
            'kind' => 'user_role',
            'user_id' => $userId,
            'email' => (string) $plan['email'],
            'role' => (string) data_get($result, 'previous.role', $from),
            'after' => (string) $plan['to'],
        ]);
    }

    /** @param array<string, mixed> $restore */
    private function revertUserRole(Site $site, array $restore): array
    {
        $userId = (int) $restore['user_id'];

        if ($this->rolesOf($site, $userId, (string) $restore['email']) !== [(string) $restore['after']]) {
            return $this->refuse(SiteChangeApplier::STALE);
        }

        $this->call($site, 'wp_user_role_set', ['user_id' => $userId, 'role' => (string) $restore['role']]);

        return $this->ok(null);
    }

    // --- Shop ----------------------------------------------------------------

    /** @param array<string, mixed> $plan */
    private function coupon(Site $site, array $plan): array
    {
        $created = $this->call($site, 'wc_coupon_create', (array) $plan['fields']);

        return $this->ok([
            'kind' => 'coupon',
            'code' => (string) ($created['code'] ?? $plan['fields']['code']),
        ]);
    }

    /**
     * Ending a coupon, never deleting it: an order that already used it keeps
     * showing the discount it got.
     *
     * @param  array<string, mixed>  $restore
     */
    private function revertCoupon(Site $site, array $restore): array
    {
        $this->call($site, 'wc_coupon_expire', ['code' => (string) $restore['code']]);

        return $this->ok(null);
    }

    /** @param array<string, mixed> $plan */
    private function productCreate(Site $site, array $plan): array
    {
        $created = $this->call($site, 'wc_product_create', (array) $plan['fields']);
        $id = (int) ($created['id'] ?? 0);

        // No undo: a draft is invisible to shoppers, and there is no tool that
        // deletes a product — which is the right way round for a phone.
        return $this->ok(null, $id > 0
            ? "המוצר נוצר כטיוטה (מזהה {$id}). כשתרצו לפרסם אותו — כתבו לי."
            : null);
    }

    // --- Helpers -------------------------------------------------------------

    /**
     * A plugin tool's answer, decoded. Its errors are exceptions, which apply()
     * turns into a failure the owner is told about in plain words.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function call(Site $site, string $tool, array $arguments): array
    {
        $decoded = json_decode($this->mcp->textContent($this->mcp->callTool($site, $tool, $arguments, 60)), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array{title: string, content: string, status: string, excerpt: string}|null */
    private function post(Site $site, int $id): ?array
    {
        try {
            $post = $this->call($site, 'wp_content_get', ['id' => $id]);
        } catch (\Throwable) {
            return null;
        }

        if (! isset($post['id'])) {
            return null;
        }

        return [
            'title' => (string) ($post['title'] ?? ''),
            'content' => (string) ($post['content'] ?? ''),
            'status' => (string) ($post['status'] ?? ''),
            'excerpt' => (string) ($post['excerpt'] ?? ''),
        ];
    }

    /**
     * The roles a user has now, found through the email the list searches on.
     *
     * @return list<string>|null null when the user could not be found
     */
    private function rolesOf(Site $site, int $userId, string $email): ?array
    {
        $users = (array) ($this->call($site, 'wp_user_list', ['search' => $email, 'limit' => 20])['users'] ?? []);

        foreach ($users as $user) {
            if ((int) ($user['id'] ?? 0) === $userId) {
                return array_values((array) ($user['roles'] ?? []));
            }
        }

        return null;
    }

    /**
     * Do these fields read the same on the live item? Line endings and the
     * edges are forgiven, the way SiteChangeApplier forgives them.
     *
     * @param  array<string, string>  $live
     * @param  array<string, mixed>  $expected
     */
    private function sameFields(array $live, array $expected): bool
    {
        $normalize = fn (mixed $text): string => trim(str_replace("\r\n", "\n", (string) $text));

        foreach ($expected as $field => $value) {
            if ($normalize($live[$field] ?? '') !== $normalize($value)) {
                return false;
            }
        }

        return true;
    }

    private function orderLabel(string $status): string
    {
        return SiteActionProposer::ORDER_STATUSES[$status] ?? $status;
    }

    /**
     * @param  array<string, mixed>|null  $restore
     * @return array{ok: true, reason: null, message: null, restore: array<string, mixed>|null, done?: string}
     */
    private function ok(?array $restore, ?string $done = null): array
    {
        return array_filter(
            ['ok' => true, 'reason' => null, 'message' => null, 'restore' => $restore, 'done' => $done],
            fn ($value, string $key): bool => $key !== 'done' || $value !== null,
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /** Same wording rules as SiteChangeApplier::failure(): the detail is for the team. */
    private function failure(string $reason): array
    {
        return [
            'ok' => false,
            'reason' => $reason,
            'message' => 'משהו השתבש מול האתר. נסו שוב, ואם זה חוזר — נשמח לעזור.',
            'restore' => null,
        ];
    }

    private function refuse(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason, 'message' => $reason, 'restore' => null];
    }
}
