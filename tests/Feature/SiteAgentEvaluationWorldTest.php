<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Services\SiteAgent\Evaluation\EvaluationMcpClient;
use App\Services\SiteAgent\Evaluation\EvaluationWhatsAppClient;
use App\Services\SiteAgent\Evaluation\EvaluationWorld;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

/** Checks simulator contracts, not live model or real WordPress behavior. */
class SiteAgentEvaluationWorldTest extends TestCase
{
    public function test_product_count_and_paginated_search_do_not_depend_on_the_last_created_product(): void
    {
        $world = new EvaluationWorld;
        $this->assertSame(40, $world->handle('wc_product_counts')['products']['total']);
        $found = $world->handle('wc_product_search', ['search' => 'חולצה', 'limit' => 1]);
        $this->assertSame(2, $found['total']);
        $this->assertSame(1, $found['returned']);
        $this->assertSame(2, $found['pages']);
        $this->assertSame(7, $found['products'][0]['id']);
        $this->assertSame(8, $world->handle('wc_product_search', ['search' => 'חולצה', 'limit' => 1, 'page' => 2])['products'][0]['id']);
        $created = $world->handle('wc_product_create', ['name' => 'ספר דיגיטלי', 'virtual' => true, 'regular_price' => '90']);
        $this->assertSame(40000, $created['id']);
        $this->assertTrue($world->handle('wc_product_get', ['product_id' => 40000])['virtual']);
        $this->assertSame('90.00', $world->state['products'][40000]['regular_price']);
        $this->assertSame('draft', $world->state['products'][40000]['status']);
        $this->assertSame(41, $world->handle('wc_product_counts')['products']['total']);
    }

    public function test_virtual_and_content_mutations_return_the_real_previous_state_and_support_restoration(): void
    {
        $world = new EvaluationWorld;
        $result = $world->handle('wc_product_update', ['product_id' => 33852, 'virtual' => true]);
        $this->assertFalse($result['previous']['virtual']);
        $this->assertTrue($world->state['products'][33852]['virtual']);
        $world->handle('wc_product_update', ['product_id' => 33852, 'virtual' => $result['previous']['virtual']]);
        $this->assertFalse($world->state['products'][33852]['virtual']);
        $result = $world->handle('wp_content_update', ['id' => 43, 'content' => 'כמה נחמד שבאת.']);
        $this->assertSame('איזה כייף שבאת. הטלפון שלנו 03-1234567.', $result['previous']['content']);
        $world->handle('wp_content_update', ['id' => 43, ...$result['previous']]);
        $this->assertSame('איזה כייף שבאת. הטלפון שלנו 03-1234567.', $world->state['content'][43]['content']);
    }

    public function test_an_invalid_write_does_not_partially_mutate_the_world_and_is_logged_as_failed(): void
    {
        $world = new EvaluationWorld;
        $before = $world->state;
        try {
            $world->handle('wc_product_update', ['product_id' => 7, 'name' => 'Changed', 'sale_price' => '200']);
            $this->fail('Invalid sale was accepted');
        } catch (InvalidArgumentException) {
            $this->assertSame($before, $world->state);
            $this->assertTrue($world->calls[0]['write']);
            $this->assertFalse($world->calls[0]['changed']);
            $this->assertArrayHasKey('error', $world->calls[0]);
        }
        try {
            $world->handle('wp_arbitrary_delete_everything');
            $this->fail('Unsupported tool was accepted');
        } catch (LogicException) {
            $this->assertSame($before, $world->state);
            $this->assertSame('wp_arbitrary_delete_everything', $world->calls[1]['tool']);
            $this->assertArrayNotHasKey('result', $world->calls[1]);
        }
    }

    public function test_compare_and_swap_guards_media_and_profile_changes(): void
    {
        $world = new EvaluationWorld;
        $result = $world->handle('wp_post_thumbnail_set', ['id' => 33852, 'attachment_id' => 91, 'if_current' => 99]);
        $this->assertFalse($result['changed']);
        $this->assertSame(0, $world->state['products'][33852]['thumbnail_id']);
        $result = $world->handle('wp_post_thumbnail_set', ['id' => 33852, 'attachment_id' => 91, 'if_current' => 0]);
        $this->assertTrue($result['changed']);
        $this->assertSame(['attachment_id' => 0], $result['previous']);
        $read = $world->handle('wp_user_profile_get', ['id' => 5]);
        $world->handle('wp_user_profile_update', ['id' => 5, 'values' => ['display_name' => 'נועה לוי'], 'expected' => $read['values']]);
        $this->assertSame('נועה לוי', $world->state['users'][5]['display_name']);
        $this->expectException(InvalidArgumentException::class);
        $world->handle('wp_user_profile_update', ['id' => 5, 'values' => ['display_name' => 'נועה אחרת'], 'expected' => $read['values']]);
    }

    public function test_protected_users_and_unsupported_fields_are_rejected(): void
    {
        $world = new EvaluationWorld;
        foreach ([['wp_user_role_set', ['user_id' => 1, 'role' => 'subscriber']], ['wp_user_role_set', ['user_id' => 5, 'role' => 'administrator']], ['wc_product_update', ['product_id' => 7, 'arbitrary_meta' => 'value']]] as [$tool, $args]) {
            $before = $world->state;
            try {
                $world->handle($tool, $args);
                $this->fail('Unsafe simulated write accepted');
            } catch (InvalidArgumentException) {
                $this->assertSame($before, $world->state);
            }
        }
    }

    public function test_native_read_envelopes_and_targeted_operations_match_the_consumers_contract(): void
    {
        $world = new EvaluationWorld;
        $this->assertSame('page', $world->handle('wp_content_get', ['id' => 43])['type']);
        $this->assertSame(['חדשות'], $world->handle('wp_post_terms_get', ['id' => 46, 'taxonomy' => 'category'])['terms']);
        $this->assertSame(2, $world->handle('wp_media_list')['total']);
        $this->assertSame('לוגו החברה', $world->handle('wp_media_get', ['id' => 90])['values']['title']);
        $this->assertSame('hero', $world->handle('wp_elementor_texts_get', ['id' => 48])['texts'][0]['widget_id']);
        $this->assertSame('לומדים ביחד', $world->handle('wp_elementor_text_update', ['id' => 48, 'widget_id' => 'hero', 'setting' => 'title', 'text' => 'לומדים אחרת'])['previous']);
        $this->assertSame('לומדים אחרת', $world->state['elementor'][48]['hero']['text']);
        $read = $world->handle('wp_internal_links_get', ['id' => 43]);
        $world->handle('wp_internal_link_update', ['id' => 43, 'values' => ['text' => 'הטלפון שלנו', 'target_id' => 44], 'expected' => $read['values']]);
        $this->assertStringContainsString('<a href="https://evaluation.example/?p=44">הטלפון שלנו</a>', $world->state['content'][43]['content']);
    }

    public function test_world_reset_removes_every_prior_mutation_and_resets_created_ids_and_call_history(): void
    {
        $world = new EvaluationWorld;
        $initial = $world->state;
        $world->handle('wc_product_create', ['name' => 'Temporary']);
        $world->handle('wp_menu_item_add', ['menu' => 'ראשי', 'title' => 'אודות', 'page_id' => 45]);
        $world->handle('wc_coupon_create', ['code' => 'new10', 'amount' => 10]);
        $this->assertSame('10.00', $world->state['coupons']['new10']['amount']);
        $this->assertSame(100, $world->state['menus'][2]['items'][100]['item_id']);
        $world->reset();
        $this->assertSame($initial, $world->state);
        $this->assertSame([], $world->calls);
    }

    public function test_reports_are_computed_from_the_requested_period_and_current_order_state(): void
    {
        $world = new EvaluationWorld;
        $read = $world->handle('wc_sales_report', ['from' => '2026-10-09', 'to' => '2026-10-09']);
        $this->assertSame(1, $read['paid_orders']);
        $this->assertSame('150.00', $read['net_sales']);
        $this->assertSame(0, $world->handle('wc_sales_report', ['from' => '2026-09-01', 'to' => '2026-09-30'])['paid_orders']);
        $world->handle('wc_order_status_set', ['order_id' => 1001, 'expected_status' => 'processing', 'status' => 'cancelled']);
        $this->assertSame('0.00', $world->handle('wc_sales_report', ['from' => '2026-10-09', 'to' => '2026-10-09'])['net_sales']);
    }

    public function test_mcp_rejects_a_different_site_or_a_nonisolated_endpoint(): void
    {
        $world = new EvaluationWorld;
        $client = new EvaluationMcpClient($world, 99);
        foreach ([[99, 'customer.example', 'https://customer.example/wp-json/multioto/v1/mcp'], [98, 'evaluation.example', 'https://evaluation.example/wp-json/multioto/v1/mcp'], [99, 'evaluation.example', 'https://different.example/mcp']] as [$id, $domain, $endpoint]) {
            $site = new Site;
            $site->id = $id;
            $site->domain = $domain;
            $site->mcp_endpoint = $endpoint;
            try {
                $client->callTool($site, 'wp_health');
                $this->fail('Evaluation allowed an unrelated site');
            } catch (LogicException) {
                $this->assertSame([], $world->calls);
            }
        }
    }

    public function test_simulated_mcp_and_image_input_cannot_make_an_http_request_or_send_whatsapp(): void
    {
        Http::preventStrayRequests();
        $world = new EvaluationWorld;
        $site = new Site;
        $site->id = 99;
        $site->domain = 'evaluation.example';
        $site->mcp_endpoint = 'https://evaluation.example/wp-json/multioto/v1/mcp';
        $client = new EvaluationMcpClient($world, 99);
        $reply = $client->callTool($site, 'wc_product_counts');
        $this->assertSame(40, json_decode($client->textContent($reply), true)['products']['total']);
        $this->assertNotEmpty($client->listTools($site));
        $media = (new EvaluationWhatsAppClient)->downloadMedia('evaluation-photo');
        $this->assertSame('png', $media['extension']);
        $upload = $world->handle('wp_media_upload', ['filename' => 'photo.png', 'data' => base64_encode($media['bytes']), 'alt' => 'כוס כחולה']);
        $this->assertSame(500, $upload['id']);
        $this->assertSame('כוס כחולה', $world->state['media'][500]['alt']);
        Http::assertNothingSent();
        $this->expectException(LogicException::class);
        (new EvaluationWhatsAppClient)->sendText('972500000000', 'Must never leave evaluation');
    }
}
