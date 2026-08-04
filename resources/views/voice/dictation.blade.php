{{--
    הכתבה קולית בכל שדה טקסט בפאנל.

    מאזין אחד על הדף כולו, ולא רכיב לכל שדה: בפאנל יש עשרות טפסים, חלקם נפתחים
    בחלוניות ובחלקם השדות נוצרים תוך כדי — וכל גישה שדורשת לגעת בכל טופס בנפרד
    נשארת חלקית ביום שאחרי. כאן, כל שדה שאפשר להקליד בו מקבל מיקרופון בעצם זה
    שנכנסו אליו.

    התמלול נעשה בדפדפן. אין מודל, אין שרת, ואין הקלטה שיוצאת מהמכשיר דרכנו —
    וזו גם הסיבה שזה לא דורש להתקין דבר.
--}}
<script data-navigate-once>
(function () {
    var Engine = window.SpeechRecognition || window.webkitSpeechRecognition;

    if (! Engine) {
        return; // פיירפוקס וכדומה — עדיף בלי כפתור מאשר עם כפתור שלא עושה כלום.
    }

    var TYPES = ['text', 'search', 'email', 'tel', 'url', ''];
    var field = null;      // השדה שאליו מכתיבים כרגע
    var listening = false;
    var recognition = null;
    var button = null;

    /* שדה שאפשר להכתיב אליו. סיסמאות לא — קול הוא הדרך הגרועה ביותר למסור סוד. */
    function dictatable(el) {
        if (! el || el.disabled || el.readOnly) {
            return false;
        }

        if (el.isContentEditable) {
            return true;
        }

        var tag = el.tagName;

        if (tag === 'TEXTAREA') {
            return true;
        }

        return tag === 'INPUT' && TYPES.indexOf((el.getAttribute('type') || '').toLowerCase()) !== -1;
    }

    function makeButton() {
        var el = document.createElement('button');

        el.type = 'button';
        el.setAttribute('aria-label', 'הכתבה קולית');
        el.title = 'הכתבה קולית (Ctrl+Shift+ר)';
        el.style.cssText = [
            'position:absolute', 'z-index:9999', 'width:26px', 'height:26px',
            'display:none', 'align-items:center', 'justify-content:center',
            'border-radius:9999px', 'border:1px solid rgba(120,120,130,.35)',
            'background:rgba(255,255,255,.96)', 'cursor:pointer', 'padding:0',
            'box-shadow:0 1px 2px rgba(0,0,0,.08)', 'font-size:13px', 'line-height:1',
        ].join(';');
        el.innerHTML = '<span aria-hidden="true">🎙</span>';

        // חשוב: לא לגנוב את המיקוד מהשדה בלחיצה — אחרת מכתיבים לשומקום.
        el.addEventListener('mousedown', function (e) { e.preventDefault(); });
        el.addEventListener('click', function (e) { e.preventDefault(); toggle(); });

        document.body.appendChild(el);

        return el;
    }

    function place() {
        if (! button || ! field || ! document.contains(field)) {
            hide();

            return;
        }

        var box = field.getBoundingClientRect();

        if (box.width === 0 && box.height === 0) {
            hide();

            return;
        }

        // בצד השמאלי-עליון של השדה: בממשק ימין-לשמאל זה הצד הפנוי.
        button.style.display = 'inline-flex';
        button.style.top = (window.scrollY + box.top + 4) + 'px';
        button.style.left = (window.scrollX + box.left + 4) + 'px';
    }

    function hide() {
        if (button) {
            button.style.display = 'none';
        }
    }

    function paint() {
        if (! button) {
            return;
        }

        button.style.borderColor = listening ? 'rgba(220,38,38,.9)' : 'rgba(120,120,130,.35)';
        button.style.background = listening ? 'rgba(254,226,226,.98)' : 'rgba(255,255,255,.96)';
        button.setAttribute('aria-pressed', listening ? 'true' : 'false');
    }

    /* הטקסט נכנס במקום הסמן ומצטרף למה שכבר כתוב — לא מוחק אותו. */
    function insert(text) {
        if (! field || ! document.contains(field)) {
            return;
        }

        field.focus();

        if (field.isContentEditable) {
            document.execCommand('insertText', false, text);
        } else {
            var start = field.selectionStart, end = field.selectionEnd, value = field.value || '';

            if (start === null || start === undefined) {
                field.value = value + text;
            } else {
                var before = value.slice(0, start);
                var spacer = (before !== '' && ! /\s$/.test(before)) ? ' ' : '';

                field.value = before + spacer + text + value.slice(end);
                field.selectionStart = field.selectionEnd = start + spacer.length + text.length;
            }
        }

        // בלי זה Livewire ו-Alpine לא יודעים שהערך השתנה, והשמירה תשלח את הישן.
        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function engine() {
        if (recognition) {
            return recognition;
        }

        recognition = new Engine();
        recognition.lang = 'he-IL';
        recognition.continuous = false;
        recognition.interimResults = false;

        recognition.onresult = function (event) {
            var said = '';

            for (var i = event.resultIndex; i < event.results.length; i++) {
                if (event.results[i].isFinal) {
                    said += event.results[i][0].transcript;
                }
            }

            if (said.trim() !== '') {
                insert(said.trim());
            }
        };

        recognition.onend = function () { listening = false; paint(); };
        recognition.onerror = function () { listening = false; paint(); };

        return recognition;
    }

    function toggle() {
        if (! field) {
            return;
        }

        if (listening) {
            engine().stop();

            return;
        }

        try {
            engine().start();
            listening = true;
        } catch (e) {
            listening = false; // start() כשכבר רץ — אין מה לדווח.
        }

        paint();
    }

    document.addEventListener('focusin', function (e) {
        if (! dictatable(e.target)) {
            return;
        }

        field = e.target;
        button = button || makeButton();
        paint();
        place();
    });

    document.addEventListener('focusout', function () {
        // רגע של חסד: לחיצה על הכפתור מוציאה מיקוד לרגע, ובלי ההשהיה הוא היה
        // נעלם בדיוק כשלוחצים עליו.
        setTimeout(function () {
            if (! dictatable(document.activeElement)) {
                hide();
            }
        }, 200);
    });

    window.addEventListener('scroll', place, true);
    window.addEventListener('resize', place);

    // קיצור מקלדת, למי שידיו כבר על המקלדת ולא רוצה לעזוב אותה.
    document.addEventListener('keydown', function (e) {
        if (e.ctrlKey && e.shiftKey && (e.code === 'KeyR' || e.key === 'ר')) {
            if (dictatable(document.activeElement)) {
                e.preventDefault();
                field = document.activeElement;
                button = button || makeButton();
                place();
                toggle();
            }
        }
    });
})();
</script>
