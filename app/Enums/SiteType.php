<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Str;

/**
 * What a site IS, functionally — an online store (WooCommerce) or a brochure /
 * presence site. Drives support/priority context and is shown as a badge. Can be
 * set by hand, or filled automatically by the agent from the installed plugins.
 */
enum SiteType: string implements HasColor, HasLabel
{
    case Store = 'store';
    case Brochure = 'brochure';

    public function getLabel(): string
    {
        return match ($this) {
            self::Store => 'חנות',
            self::Brochure => 'אתר תדמית',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Store => 'success',
            self::Brochure => 'info',
        };
    }

    /**
     * Infer the type from a plugin list: the WooCommerce CORE plugin present ⇒ a
     * store. Matches the core plugin file (woocommerce/woocommerce.php) or the
     * bare "woocommerce" slug — NOT extensions like woocommerce-gateway-stripe,
     * which would false-positive a plain substring search.
     */
    public static function fromPluginList(string $pluginListText): self
    {
        $text = Str::lower($pluginListText);

        $hasCore = str_contains($text, 'woocommerce/woocommerce.php')
            || preg_match('/(?<![\w-])woocommerce(?![\w-])/', $text) === 1;

        return $hasCore ? self::Store : self::Brochure;
    }
}
