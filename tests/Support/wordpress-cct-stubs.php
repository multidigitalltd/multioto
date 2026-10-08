<?php

/**
 * Isolated vendor API contract double; signatures mirror Crocoblock's published
 * CCT CRUD gist and get_formatted_fields() export example, linked in class-cct.
 * This does not replace a compatibility test on a licensed JetEngine site.
 */

namespace Jet_Engine\Modules\Custom_Content_Types {
    class Module
    {
        public static $module;

        public static function instance()
        {
            return self::$module;
        }
    }
}

namespace {
    define('ABSPATH', __DIR__.'/');
    define('ARRAY_A', 'ARRAY_A');

    class Multioto_Agent_Rpc_Error extends RuntimeException
    {
        public function __construct(int $code, string $message)
        {
            parent::__construct($message, $code);
        }
    }

    class CctContractStore
    {
        public array $items = [];

        public array $writes = [];

        public array $queries = [];

        public array $prepared = [];
    }

    class CctContractDatabase
    {
        public string $format = 'OBJECT';

        public function __construct(public CctContractStore $store) {}

        public function set_format_flag($flag): void
        {
            $this->format = $flag;
        }

        public function get_item($id)
        {
            $item = $this->store->items[$id] ?? null;

            return $item && $this->format === 'OBJECT' ? (object) $item : $item;
        }

        public function query($args, $limit, $offset, $order, $rel)
        {
            $this->store->queries[] = compact('args', 'limit', 'offset', 'order', 'rel');
            $rows = array_filter($this->store->items, static function ($row) use ($args): bool {
                foreach ($args as $filter) {
                    if ((string) ($row[$filter['field']] ?? '') !== (string) $filter['value']) {
                        return false;
                    }
                }

                return true;
            });

            return array_values(array_slice($rows, $offset, $limit));
        }
    }

    class CctContractFactory
    {
        public array $args = ['name' => 'Properties', 'has_single' => false];

        public array $fields = [
            'title' => ['type' => 'text', 'title' => 'Property title', 'is_required' => true],
            'price' => ['type' => 'number', 'min_value' => 0, 'max_value' => 999999999],
            'notes' => ['type' => 'textarea'],
            'body' => ['type' => 'wysiwyg'],
            'date' => ['type' => 'date'],
            'open_at' => ['type' => 'datetime-local'],
            'time' => ['type' => 'time'],
            'active' => ['type' => 'switcher'],
            'category' => ['type' => 'select', 'options' => [['key' => 'house', 'value' => 'House'], ['key' => 'flat', 'value' => 'Flat']]],
            'color' => ['type' => 'colorpicker'],
            'gallery' => ['type' => 'gallery'],
            'repeater' => ['type' => 'repeater'],
            'api_token' => ['type' => 'text'],
            '_private' => ['type' => 'text'],
            'cct_author_id' => ['type' => 'number'],
        ];

        public CctContractDatabase $db;

        public function __construct(public CctContractStore $store)
        {
            $this->db = new CctContractDatabase($store);
        }

        public function get_formatted_fields(): array
        {
            return $this->fields;
        }

        public function get_arg($key)
        {
            return $this->args[$key] ?? null;
        }

        public function prepare_query_args($query): array
        {
            $this->store->prepared[] = $query;

            return $query;
        }

        public function get_item_handler(): object
        {
            return new class($this->store)
            {
                public function __construct(private CctContractStore $store) {}

                public function update_item($values): int
                {
                    $this->store->writes[] = $values;
                    $id = $values['_ID'] ?? (max(array_keys($this->store->items) ?: [0]) + 1);
                    $this->store->items[$id] = array_replace($this->store->items[$id] ?? [], $values, ['_ID' => $id]);

                    return $id;
                }
            };
        }
    }

    function jet_engine()
    {
        return $GLOBALS['cct_contract_engine'];
    }

    function is_wp_error($value): bool
    {
        return false;
    }

    function sanitize_text_field($value): string
    {
        return trim(strip_tags($value));
    }

    function sanitize_textarea_field($value): string
    {
        return trim(strip_tags($value));
    }

    function wp_kses_post($value): string
    {
        return strip_tags($value, '<p><a><strong>');
    }
}
