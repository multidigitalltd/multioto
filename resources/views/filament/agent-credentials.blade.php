{{--
    Ready-to-copy connection codes for the companion plugin. Every value is
    generated/stored in the panel, so a manager only copies — never invents.
    Uses Alpine (bundled with Filament) for the copy-to-clipboard buttons.
--}}
<div class="space-y-4 text-sm" dir="rtl">
    <p class="text-gray-500 dark:text-gray-400">
        העתיקו את הערכים הבאים אל התוסף באתר הלקוח (הגדרות → Multi Digital Agent).
        <strong>מפתח MCP</strong> חייב להישאר זהה בשני הצדדים.
    </p>

    @php
        $fields = [
            ['label' => 'כתובת הפאנל', 'value' => $data['panel_url'], 'hint' => 'שדה "כתובת הפאנל" בתוסף'],
            ['label' => 'כתובת MCP', 'value' => $data['mcp_endpoint'], 'hint' => 'נכתב אוטומטית גם בפאנל'],
            ['label' => 'מפתח MCP', 'value' => $data['mcp_secret'], 'hint' => 'שדה "מפתח MCP" בתוסף — זהה לפאנל'],
        ];

        // A token created before it was stored retrievably can't be shown again —
        // never rotate on view; guide the manager to rotate explicitly instead.
        if (filled($data['update_token'])) {
            $fields[] = ['label' => 'טוקן עדכון', 'value' => $data['update_token'], 'hint' => 'שדה "טוקן עדכון" בתוסף'];
        }
    @endphp

    @foreach ($fields as $field)
        <div
            x-data="{
                copied: false,
                copy() {
                    const done = () => { this.copied = true; setTimeout(() => this.copied = false, 1500); };
                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(@js($field['value'])).then(done);
                    } else {
                        // Fallback for non-HTTPS/older browsers.
                        const ta = document.createElement('textarea');
                        ta.value = @js($field['value']);
                        ta.style.position = 'fixed'; ta.style.opacity = '0';
                        document.body.appendChild(ta); ta.focus(); ta.select();
                        try { document.execCommand('copy'); done(); } finally { ta.remove(); }
                    }
                },
            }"
            class="rounded-lg border border-gray-200 p-3 dark:border-gray-700"
        >
            <div class="mb-1 flex items-center justify-between gap-2">
                <span class="font-semibold text-gray-700 dark:text-gray-200">{{ $field['label'] }}</span>
                <button
                    type="button"
                    x-on:click="copy()"
                    class="inline-flex items-center gap-1 rounded-md bg-primary-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500"
                >
                    <span x-show="! copied">העתק</span>
                    <span x-show="copied" x-cloak>הועתק ✓</span>
                </button>
            </div>
            <code class="block select-all break-all rounded bg-gray-50 p-2 font-mono text-xs text-gray-800 dark:bg-gray-900 dark:text-gray-100">{{ $field['value'] }}</code>
            <p class="mt-1 text-xs text-gray-400">{{ $field['hint'] }}</p>
        </div>
    @endforeach

    @if (blank($data['update_token']))
        <div class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs text-amber-800 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200">
            <strong>טוקן עדכון:</strong> לאתר כבר קיים טוקן שנוצר בעבר ואינו ניתן לשחזור להצגה.
            אם צריך להתקין/לחבר מחדש — לחצו "טוקן חדש" כדי לייצר טוקן להעתקה (הטוקן הקודם יבוטל).
        </div>
    @endif

    <p class="text-xs text-gray-400">
        הערכים נשמרים בפאנל — אפשר לחזור ולהעתיק אותם בכל עת. יצירת "טוקן חדש" תבטל את הקודם.
    </p>
</div>
