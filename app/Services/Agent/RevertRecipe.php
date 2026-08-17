<?php

namespace App\Services\Agent;

/**
 * How to undo a change, derived from what the change actually did.
 *
 * The journal has always been able to store an inverse call — a tool and its
 * arguments — and the panel offers "שחזר" whenever one is present. What it had
 * no way to obtain was the inverse of a content or price edit: whoever proposed
 * the change would have had to predict the previous value and send it along,
 * and a prediction made before the call is exactly the thing that is wrong when
 * two edits land in the same minute.
 *
 * So the recipe is built here, afterwards, from the `previous` block the write
 * tools return. It describes the state the site was actually in a moment ago,
 * not the state somebody expected it to be in.
 *
 * Only the fields that changed are restored. A revert that also rewrote the
 * title of a page whose title nobody touched would undo somebody else's work
 * while claiming to undo ours.
 */
class RevertRecipe
{
    /**
     * The inverse of a tool call, or null when there is nothing to derive.
     *
     * Null is not a failure: a tool that reports no previous state (a cache
     * flush, a plugin activation) is handled the way it always was — by an
     * explicitly supplied recipe, or not at all. Guessing an inverse for a call
     * that did not describe its own effect is how a "revert" ends up making a
     * second, different change.
     *
     * @param  array<string, mixed>  $arguments  the call that was made
     * @param  string  $output  the tool's raw text output
     * @return array{tool: string, arguments: array<string, mixed>}|null
     */
    public function for(string $tool, array $arguments, string $output): ?array
    {
        $decoded = json_decode($output, true);

        if (! is_array($decoded) || ! isset($decoded['previous'])) {
            return null;
        }

        $previous = $decoded['previous'];

        return match ($tool) {
            'wp_content_update' => $this->fromMap($tool, ['id' => $arguments['id'] ?? null], $previous),
            'wp_fields_update' => is_array($previous) && $previous !== []
                ? ['tool' => $tool, 'arguments' => ['id' => $arguments['id'] ?? null, 'fields' => $previous]]
                : null,
            'wp_elementor_text_update' => is_string($previous)
                ? ['tool' => $tool, 'arguments' => [
                    'id' => $arguments['id'] ?? null,
                    'widget_id' => $arguments['widget_id'] ?? null,
                    // The setting the tool reports, not the one that was asked
                    // for: the caller may have omitted it and let the tool pick.
                    'setting' => $decoded['setting'] ?? ($arguments['setting'] ?? null),
                    'text' => $previous,
                ]]
                : null,
            'wc_product_update' => $this->product($arguments, $decoded),
            default => null,
        };
    }

    /**
     * A product's inverse: the previous value of each field that changed.
     *
     * `changed` rather than the whole previous snapshot, because the snapshot
     * describes every field of the product — restoring all of them would revert
     * a price somebody else corrected in between, on the strength of a change
     * that never touched it.
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $decoded
     * @return array{tool: string, arguments: array<string, mixed>}|null
     */
    private function product(array $arguments, array $decoded): ?array
    {
        $previous = (array) ($decoded['previous'] ?? []);
        $changed = array_keys((array) ($decoded['changed'] ?? []));

        $restore = [];

        foreach ($changed as $field) {
            if (! array_key_exists($field, $previous)) {
                continue;
            }

            // A field that had no value becomes an empty string rather than
            // null: that is how the tools spell "clear this", and null would be
            // read as "leave it alone" — which would leave the change in place.
            $restore[$field] = $previous[$field] ?? '';
        }

        if ($restore === []) {
            return null;
        }

        return ['tool' => 'wc_product_update', 'arguments' => ['product_id' => $decoded['updated_id'] ?? ($arguments['product_id'] ?? null)] + $restore];
    }

    /**
     * @param  array<string, mixed>  $identity
     * @param  mixed  $previous
     * @return array{tool: string, arguments: array<string, mixed>}|null
     */
    private function fromMap(string $tool, array $identity, $previous): ?array
    {
        if (! is_array($previous) || $previous === []) {
            return null;
        }

        return ['tool' => $tool, 'arguments' => $identity + $previous];
    }
}
