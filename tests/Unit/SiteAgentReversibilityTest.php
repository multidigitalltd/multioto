<?php

namespace Tests\Unit;

use App\Models\SiteAgentRequest;
use App\Services\SiteAgent\SiteAgentReversibility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SiteAgentReversibilityTest extends TestCase
{
    #[DataProvider('irreversibleChanges')]
    public function test_irreversible_changes_are_refused_before_and_after_a_proposal(string $operation, array $fields): void
    {
        $this->assertNotNull(SiteAgentReversibility::reasonFor($operation, $fields));
    }

    public static function irreversibleChanges(): array
    {
        return [
            'media proposal' => ['propose_media_delete', ['attachment_id' => 25]],
            'saved media plan' => [SiteAgentRequest::OP_MEDIA_DELETE, ['attachment_id' => 25]],
            'media tool' => ['wp_media_delete', ['attachment_id' => 25]],
            'subscription proposal' => ['propose_subscription_status', ['status' => 'cancelled']],
            'saved subscription plan' => [SiteAgentRequest::OP_SUBSCRIPTION_STATUS, ['from' => 'active', 'to' => 'cancelled']],
            'prefixed subscription status' => ['propose_subscription_status', ['status' => ' WC-CANCELLED ']],
            'contradictory subscription targets' => ['subscription_status', ['to' => 'active', 'status' => 'cancelled']],
            'emailed order note proposal' => ['propose_order_note', ['to_customer' => true]],
            'emailed order note plan' => [SiteAgentRequest::OP_ORDER_NOTE, ['to_customer' => true]],
            'emailed order note tool' => ['wc_order_note_add', ['customer_note' => 'true']],
            'contradictory customer note flags' => ['order_note', ['to_customer' => false, 'customer_note' => true]],
            'plugin update proposal without restoration' => ['propose_plugin_update', ['plugins' => ['all']]],
            'saved plugin update without restoration' => [SiteAgentRequest::OP_PLUGIN_UPDATE, []],
            'theme update proposal without restoration' => ['propose_theme_update', ['stylesheet' => 'theme']],
            'saved theme update without restoration' => [SiteAgentRequest::OP_THEME_UPDATE, []],
            'menu item removal proposal' => ['propose_menu_item_remove', ['item_id' => 12]],
            'saved menu item removal' => [SiteAgentRequest::OP_MENU_REMOVE, ['item_id' => 12]],
            'untrusted backup flag does not unlock updates' => [SiteAgentRequest::OP_PLUGIN_UPDATE, ['backup_verified' => true]],
            'permanent content deletion' => ['delete_content', []],
            'permanent product deletion' => ['wc_product_delete', []],
            'permanent user deletion' => ['wp_user_delete', []],
            'permanent cct deletion' => ['jet_cct_delete', []],
            'permanent comment deletion' => ['propose_comment_moderation', ['status' => 'delete']],
        ];
    }

    #[DataProvider('recoverableChanges')]
    public function test_recoverable_changes_remain_available_even_without_one_step_undo(string $operation, array $fields): void
    {
        $this->assertNull(SiteAgentReversibility::reasonFor($operation, $fields));
    }

    public static function recoverableChanges(): array
    {
        return [
            'private note' => ['propose_order_note', ['to_customer' => false]],
            'private note with string false' => ['propose_order_note', ['to_customer' => 'false']],
            'private note by default' => [SiteAgentRequest::OP_ORDER_NOTE, []],
            'pause subscription' => ['propose_subscription_status', ['status' => 'on-hold']],
            'resume subscription' => [SiteAgentRequest::OP_SUBSCRIPTION_STATUS, ['from' => 'on-hold', 'to' => 'active']],
            'cancel at period end' => [SiteAgentRequest::OP_SUBSCRIPTION_STATUS, ['to' => 'pending-cancel']],
            'cancel order keeps record and stock can be restored' => [SiteAgentRequest::OP_ORDER_STATUS, ['to' => 'cancelled']],
            'complete order keeps record' => ['propose_order_status', ['status' => 'completed']],
            'trash content' => [SiteAgentRequest::OP_TRASH, []],
            'trash product' => [SiteAgentRequest::OP_PRODUCT_TRASH, []],
            'trash comment' => ['propose_comment_moderation', ['status' => 'trash']],
            'create bounded user' => [SiteAgentRequest::OP_USER_CREATE, ['fields' => ['role' => 'subscriber']]],
            'create product' => [SiteAgentRequest::OP_PRODUCT_CREATE, []],
            'create term' => [SiteAgentRequest::OP_TERM_CREATE, []],
            'regenerate cache' => [SiteAgentRequest::OP_CACHE_FLUSH, []],
            'toggle existing plugin' => [SiteAgentRequest::OP_PLUGIN_TOGGLE, []],
        ];
    }

    public function test_reversible_status_changes_disclose_non_recallable_side_effects(): void
    {
        $notice = SiteAgentReversibility::sideEffectNoticeFor('propose_order_status', ['status' => 'completed']);

        $this->assertStringContainsString('החזרת הסטטוס אינה מבטלת הודעות', $notice);
        $this->assertStringContainsString('אימייל', SiteAgentReversibility::sideEffectNoticeFor('propose_user_create'));
        $this->assertStringContainsString('אחרי סיום התקופה', SiteAgentReversibility::sideEffectNoticeFor('subscription_status', ['to' => 'pending-cancel']));
        $this->assertNull(SiteAgentReversibility::sideEffectNoticeFor(SiteAgentRequest::OP_POST_UPDATE));
    }
}
