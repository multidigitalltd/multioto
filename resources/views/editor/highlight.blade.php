{{--
    כפתור הדגשה (רקע צהוב) בכל עורך טקסט עשיר בפאנל.

    לקוחות מסמנים לנו את המשפט החשוב, ומאז שההדגשה שלהם שורדת את הסינון היה
    מוזר שאי אפשר להשיב באותה שפה. Trix — העורך שמתחת ל-RichEditor — יודע
    לנהל תכונות טקסט משלו; כאן נרשמת תכונה אחת שמפיקה <mark>, שהוא בדיוק הסימון
    שהרשימה הלבנה שלנו כבר מכירה בשני הכיוונים.

    מאזין אחד על הדף ולא רכיב לכל עורך: עורכים נפתחים בחלוניות ובטפסים שנוצרים
    תוך כדי, וכל גישה שדורשת לגעת בכל אחד מהם בנפרד נשארת חלקית ביום שאחרי.
--}}
<script data-navigate-once>
(function () {
    var ATTRIBUTE = 'highlight';

    /* נרשם פעם אחת, ברגע ש-Trix נטען — הוא נטען עם העורך הראשון ולא לפניו. */
    function register() {
        if (! window.Trix || window.Trix.config.textAttributes[ATTRIBUTE]) {
            return !! window.Trix;
        }

        // inheritable: false — ההדגשה לא נמרחת על טקסט שנכתב אחריה.
        window.Trix.config.textAttributes[ATTRIBUTE] = { tagName: 'mark', inheritable: false };

        return true;
    }

    function button(toolbar) {
        var group = toolbar.querySelector('[data-trix-button-group="text-tools"]');

        if (! group || group.querySelector('[data-trix-attribute="' + ATTRIBUTE + '"]')) {
            return;
        }

        var el = document.createElement('button');

        el.type = 'button';
        // אותן מחלקות של שאר הכפתורים בקבוצה, כדי שייראה כמו אחד מהם ולא כמו
        // תוספת: המראה נגזר מהאח שלידו ולא משוכפל ביד.
        el.className = (group.querySelector('button') || {}).className || '';
        el.setAttribute('data-trix-attribute', ATTRIBUTE);
        el.setAttribute('data-trix-key', 'h');
        el.setAttribute('title', 'הדגשה (Ctrl+Shift+H)');
        el.setAttribute('aria-label', 'הדגשה');
        el.innerHTML = '<span aria-hidden="true" style="display:inline-block;width:1rem;height:1rem;'
            + 'border-radius:.2rem;background:#fde047;border:1px solid rgba(0,0,0,.15)"></span>';

        group.appendChild(el);
    }

    document.addEventListener('trix-initialize', function (event) {
        if (! register()) {
            return;
        }

        var editor = event.target;
        var toolbar = editor.toolbarElement || document.getElementById(editor.getAttribute('toolbar'));

        if (toolbar) {
            button(toolbar);
        }
    });
})();
</script>
