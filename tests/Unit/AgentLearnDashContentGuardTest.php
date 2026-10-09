<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class AgentLearnDashContentGuardTest extends TestCase
{
    private function execute(array $input): array
    {
        $process = new Process([PHP_BINARY, __DIR__.'/../Support/learndash-content-guards.php']);
        $process->setInput(json_encode($input, JSON_THROW_ON_ERROR));
        $process->mustRun();

        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function field(string $name, string $type = 'text', array $extra = []): array
    {
        return $extra + ['key' => 'field_'.$name, 'name' => $name, 'label' => $name, 'type' => $type];
    }

    public function test_learndash_structural_meta_cannot_be_changed_through_generic_meta_even_in_a_mixed_batch(): void
    {
        foreach (['course_id', 'lesson_id', 'topic_id', 'quiz_pro_id', 'sfwd-quiz', 'sfwd_course_price', 'learndash_group_users_5', 'course_12_access_from', 'course_completed_12'] as $key) {
            $result = $this->execute(['active' => true, 'action' => 'update', 'args' => ['public_note' => 'Safe', $key => 5]]);
            self::assertSame(-32602, $result['error'], $key);
            self::assertSame([], $result['writes'], $key);
        }
    }

    public function test_unrelated_sites_and_custom_course_id_fields_remain_editable(): void
    {
        foreach ([['active' => false, 'post_type' => 'project'], ['active' => true, 'post_type' => 'project']] as $site) {
            $result = $this->execute($site + ['action' => 'update', 'args' => ['course_id' => 'external-catalog-12', 'public_note' => 'Text']]);
            self::assertCount(2, $result['writes']);
            self::assertArrayNotHasKey('error', $result);
        }
        $inactive = $this->execute(['active' => false, 'post_type' => 'project', 'action' => 'update', 'args' => ['learndash_note' => 'An unrelated integration']]);
        self::assertCount(1, $inactive['writes']);
    }

    public function test_legacy_reads_hide_native_learndash_meta_but_keep_ordinary_custom_content(): void
    {
        $result = $this->execute(['active' => true, 'action' => 'read', 'meta' => ['course_id' => 3, 'learndash_group_users_5' => [1, 2], 'course_12_access_from' => 123, 'teaser' => 'Visible']]);
        self::assertSame(['teaser' => 'Visible'], $result['result']);
    }

    public function test_acf_registered_system_names_are_protected_without_blocking_grouped_custom_ids(): void
    {
        $result = $this->execute(['active' => true, 'acf' => true, 'action' => 'acf', 'fields' => [
            $this->field('course_id'), $this->field('teaser'),
            $this->field('details', 'group', ['sub_fields' => [$this->field('course_id')]]),
            $this->field('borrowed', 'clone', ['sub_fields' => [$this->field('lesson_id')], 'display' => 'seamless', 'prefix_name' => 0]),
        ], 'values' => ['field_course_id' => 123, 'field_teaser' => 'Visible', 'field_details' => ['field_course_id' => 'External catalog'], 'field_borrowed' => ['field_lesson_id' => 456]]]);
        self::assertFalse($result['result']['fields'][0]['editable']);
        self::assertArrayNotHasKey('field_course_id', $result['result']['values']);
        self::assertTrue($result['result']['fields'][2]['sub_fields'][0]['editable']);
        self::assertSame('External catalog', $result['result']['values']['field_details']['field_course_id']);
        self::assertFalse($result['result']['fields'][3]['sub_fields'][0]['editable']);
        self::assertSame('[מוסתר]', $result['result']['values']['field_borrowed']['field_lesson_id']);
    }

    public function test_generic_quiz_and_question_creation_refuses_before_insert_but_course_draft_creation_remains_allowed(): void
    {
        foreach (['sfwd-quiz', 'sfwd-question'] as $type) {
            $result = $this->execute(['active' => true, 'action' => 'create', 'args' => ['type' => $type, 'title' => 'New quiz', 'content' => 'Text']]);
            self::assertSame(-32602, $result['error']);
            self::assertSame([], $result['writes']);
        }
        $course = $this->execute(['active' => true, 'action' => 'create', 'args' => ['type' => 'sfwd-courses', 'title' => 'New course']]);
        self::assertCount(1, $course['writes']);
        self::assertSame('draft', $course['writes'][0]['post_status']);
    }

    public function test_existing_course_lesson_topic_quiz_introduction_and_certificate_content_can_still_be_edited(): void
    {
        foreach (['sfwd-courses', 'sfwd-lessons', 'sfwd-topic', 'sfwd-quiz', 'sfwd-certificates'] as $type) {
            $result = $this->execute(['active' => true, 'post_type' => $type, 'action' => 'content_update', 'args' => ['id' => 11, 'title' => 'Edited title', 'content' => '<p>Edited lesson</p>']]);
            self::assertArrayNotHasKey('error', $result, json_encode($result));
            self::assertSame('Edited title', $result['writes'][0]['post_title']);
            self::assertSame('<p>Edited lesson</p>', $result['writes'][0]['post_content']);
        }
    }

    public function test_internal_learndash_records_refuse_generic_content_and_field_routes_before_data_access_or_writes(): void
    {
        $actions = [
            'create' => ['title' => 'Rewritten record', 'content' => 'Replacement'],
            'content_update' => ['id' => 11, 'title' => 'Rewritten record', 'content' => 'Replacement'],
            'content_trash' => ['id' => 11],
            'content_get' => ['id' => 11],
            'details' => [],
            'manage' => ['status' => 'publish'],
            'read' => [],
            'update' => ['public_note' => 'Replacement'],
            'schema' => [],
            'acf' => [],
        ];

        foreach (['sfwd-assignment', 'sfwd-essays', 'sfwd-transactions', 'sfwd-question'] as $type) {
            foreach ($actions as $action => $args) {
                $scenario = $type.' / '.$action;
                $result = $this->execute([
                    'active' => true, 'post_type' => $type, 'action' => $action,
                    'args' => $args + ['type' => $type], 'acf' => $action === 'acf',
                    'meta' => ['public_note' => 'Private learning record'],
                    'fields' => [$this->field('public_note')],
                    'values' => ['field_public_note' => 'Private learning record'],
                ]);
                self::assertSame(-32602, $result['error'] ?? null, $scenario.' '.json_encode($result));
                self::assertSame([], $result['writes'], $scenario);
                self::assertSame([], $result['data_reads'], $scenario);
                self::assertArrayNotHasKey('result', $result, $scenario);
            }
        }
    }

    public function test_internal_records_are_not_advertised_as_editable_but_learning_content_remains_available(): void
    {
        $active = $this->execute(['active' => true, 'action' => 'editable_types']);
        foreach (['sfwd-assignment', 'sfwd-essays', 'sfwd-transactions', 'sfwd-question'] as $type) {
            self::assertNotContains($type, $active['result']);
        }
        foreach (['post', 'page', 'project', 'sfwd-courses', 'sfwd-lessons', 'sfwd-topic', 'sfwd-quiz', 'sfwd-certificates'] as $type) {
            self::assertContains($type, $active['result']);
        }

        $inactive = $this->execute(['active' => false, 'action' => 'editable_types']);
        foreach (['sfwd-assignment', 'sfwd-essays', 'sfwd-transactions', 'sfwd-question'] as $type) {
            self::assertContains($type, $inactive['result']);
        }
    }

    public function test_unrelated_custom_content_and_fields_are_not_blocked_when_learndash_is_active_or_absent(): void
    {
        foreach ([true, false] as $active) {
            $site = ['active' => $active, 'post_type' => 'project'];
            $content = $this->execute($site + ['action' => 'content_update', 'args' => ['id' => 11, 'title' => 'Project title', 'content' => 'Project text']]);
            self::assertArrayNotHasKey('error', $content);
            self::assertSame('Project title', $content['writes'][0]['post_title']);

            $read = $this->execute($site + ['action' => 'read', 'meta' => ['public_note' => 'Project value']]);
            self::assertSame(['public_note' => 'Project value'], $read['result']);

            $schema = $this->execute($site + ['action' => 'schema', 'fields' => [$this->field('public_note')]]);
            self::assertSame('public_note', $schema['result'][0]['key']);

            $acf = $this->execute($site + ['action' => 'acf', 'acf' => true, 'fields' => [$this->field('public_note')], 'values' => ['field_public_note' => 'Project value']]);
            self::assertSame('Project value', $acf['result']['values']['field_public_note']);
        }

        // A plugin that is no longer installed does not reserve similarly
        // named custom post types belonging to another integration.
        $inactive = $this->execute(['active' => false, 'post_type' => 'sfwd-assignment', 'action' => 'content_update', 'args' => ['id' => 11, 'title' => 'Custom record']]);
        self::assertArrayNotHasKey('error', $inactive);
        self::assertSame('Custom record', $inactive['writes'][0]['post_title']);
    }

    public function test_generic_post_parent_and_order_do_not_claim_to_rewire_course_builder_steps(): void
    {
        foreach (['parent' => 12, 'menu_order' => 2] as $key => $value) {
            $result = $this->execute(['active' => true, 'action' => 'manage', 'args' => [$key => $value]]);
            self::assertSame(-32602, $result['error']);
            self::assertStringContainsString('LearnDash', $result['message']);
            self::assertSame([], $result['writes']);
        }
        $other = $this->execute(['active' => true, 'post_type' => 'project', 'action' => 'manage', 'args' => ['menu_order' => 2]]);
        self::assertArrayNotHasKey('error', $other);
        self::assertSame(2, $other['writes'][0]['menu_order']);
    }
}
