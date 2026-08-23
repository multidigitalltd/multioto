@php
    // Our own model, when one is configured. The browser's engine — which is
    // Google's, and which Firefox and Safari do not have at all — stays
    // available as the fallback, and as the only option before a model exists.
    $localModel = (bool) config('transcription.enabled') && filled(config('transcription.url'));
    $engine = in_array(config('transcription.engine'), ['auto', 'server', 'browser'], true)
        ? config('transcription.engine')
        : 'auto';
@endphp
{{--
    הכתבה קולית בכל שדה טקסט בפאנל.

    מאזין אחד על הדף כולו, ולא רכיב לכל שדה: בפאנל יש עשרות טפסים, חלקם נפתחים
    בחלוניות ובחלקם השדות נוצרים תוך כדי — וכל גישה שדורשת לגעת בכל טופס בנפרד
    נשארת חלקית ביום שאחרי. כאן, כל שדה שאפשר להקליד בו מקבל מיקרופון בעצם זה
    שנכנסו אליו.

    שני מנועים, ושניהם נשארים:

    · **מודל על השרת שלנו** — מקליטים בדפדפן, שולחים את הקובץ אלינו, ומקבלים
      טקסט. ההקלטה אינה יוצאת מהמכונה שלנו, וזה חשוב: מנהל שמכתיב מדבר על
      לקוחות בשמם. עובד בכל דפדפן שיודע להקליט, כולל פיירפוקס וספארי.

    · **המנוע של הדפדפן** (webkitSpeechRecognition) — נוח ומיידי, אבל האודיו
      עובר דרך גוגל והוא קיים רק בכרום.

    ברירת המחדל ('auto') מעדיפה את שלנו, ונופלת לדפדפן רק אחרי כישלון אמיתי —
    ואומרת זאת, כי הכתבה שמפסיקה לעבוד בלי הסבר נראית כמו תקלה במיקרופון.
    TRANSCRIPTION_ENGINE=server אוסר על הנפילה הזו, ו-browser מוותר על המודל.
--}}
<script data-navigate-once>
(function () {
    var LOCAL = @js($localModel);
    var MODE = @js($engine);
    var ENDPOINT = @js(route('agent.transcribe'));
    var MAX_SECONDS = @js((int) config('transcription.max_seconds', 120));

    var Engine = window.SpeechRecognition || window.webkitSpeechRecognition;
    var canRecord = !! (navigator.mediaDevices && window.MediaRecorder);

    var serverAvailable = LOCAL && canRecord && MODE !== 'browser';
    // 'server' אוסר על נפילה לדפדפן במפורש: יש התקנות שבהן העדפה שהאודיו לא
    // יגיע לצד שלישי חזקה מהעדפה שההכתבה תמיד תעבוד.
    var browserAvailable = !! Engine && MODE !== 'server';

    // בלי מנוע כלשהו עדיף בלי כפתור מאשר עם כפתור שלא עושה כלום.
    if (! serverAvailable && ! browserAvailable) {
        return;
    }

    // המנוע לשימוש עכשיו. יורד לדפדפן רק אחרי שהשרת נכשל בפועל — ולא מראש.
    var useServer = serverAvailable;

    var TYPES = ['text', 'search', 'email', 'tel', 'url', ''];
    var field = null;      // השדה שאליו מכתיבים כרגע
    var listening = false;
    var working = false;   // ההקלטה נשלחה ומחכים לתמלול
    var recognition = null;
    var button = null;

    // מצב ההקלטה בצד השרת.
    var recorder = null;
    var stream = null;
    var chunks = [];
    var timer = null;
    var seconds = 0;

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

    /* מה שקורה עכשיו, בקול רם — כפתור שמשנה רק צבע אינו אומר דבר לקורא מסך. */
    function announce(text) {
        var live = document.getElementById('md-dictation-status');

        if (! live) {
            live = document.createElement('p');
            live.id = 'md-dictation-status';
            live.setAttribute('role', 'status');
            live.setAttribute('aria-live', 'polite');
            // נשמע ולא נראה: המצב מוצג ויזואלית על הכפתור עצמו.
            live.style.cssText = 'position:absolute;width:1px;height:1px;margin:-1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0;padding:0';
            document.body.appendChild(live);
        }

        live.textContent = text;
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
        button.style.opacity = working ? '.55' : '1';
        button.disabled = working;
        button.setAttribute('aria-pressed', listening ? 'true' : 'false');
        button.innerHTML = '<span aria-hidden="true">' + (working ? '⏳' : '🎙') + '</span>';
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

    /* ── המנוע של הדפדפן ─────────────────────────────────────────────── */

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

    /* ── המודל שלנו ──────────────────────────────────────────────────── */

    /* המכל הטוב ביותר שהדפדפן הזה באמת ייתן. */
    function recorderOptions() {
        var types = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg'];

        for (var i = 0; i < types.length; i++) {
            if (MediaRecorder.isTypeSupported(types[i])) {
                return { mimeType: types[i] };
            }
        }

        return {};
    }

    function startRecording() {
        navigator.mediaDevices.getUserMedia({ audio: true }).then(function (granted) {
            stream = granted;
            chunks = [];
            seconds = 0;

            recorder = new MediaRecorder(stream, recorderOptions());
            recorder.ondataavailable = function (e) { if (e.data && e.data.size) { chunks.push(e.data); } };
            recorder.onstop = upload;
            recorder.start();

            listening = true;
            paint();
            announce('מקליט. לחצו שוב לעצירה.');

            timer = setInterval(function () {
                seconds++;

                // נעצר לבד, כדי שהקלטה שנשכחה לא תגדל מעבר למה שהשרת מקבל.
                if (seconds >= MAX_SECONDS) {
                    stopRecording();
                }
            }, 1000);
        }).catch(function (e) {
            listening = false;
            paint();
            // סירוב הרשאה והיעדר מיקרופון הם שתי בעיות שונות, ורק לאחת מהן יש
            // טעם לנסות שוב.
            announce(e && e.name === 'NotAllowedError'
                ? 'אין הרשאה למיקרופון — יש לאשר אותה בדפדפן.'
                : 'לא נמצא מיקרופון זמין.');
        });
    }

    function stopRecording() {
        if (timer) { clearInterval(timer); timer = null; }

        if (recorder && recorder.state !== 'inactive') {
            recorder.stop();
        }

        listening = false;
        paint();
    }

    /* בלי זה נורית ההקלטה של הדפדפן נשארת דולקת אחרי שסיימנו. */
    function releaseMicrophone() {
        if (stream) {
            stream.getTracks().forEach(function (track) { track.stop(); });
            stream = null;
        }
    }

    function upload() {
        var blob = new Blob(chunks, { type: (chunks[0] && chunks[0].type) || 'audio/webm' });

        chunks = [];
        releaseMicrophone();

        if (blob.size < 1000) {
            announce('ההקלטה קצרה מדי.');

            return;
        }

        working = true;
        paint();
        announce('מתמלל…');

        var body = new FormData();
        var token = document.querySelector('meta[name="csrf-token"]');

        body.append('audio', blob, 'recording.' + (blob.type.indexOf('mp4') !== -1 ? 'mp4' : 'webm'));

        fetch(ENDPOINT, {
            method: 'POST',
            body: body,
            headers: {
                'X-CSRF-TOKEN': token ? token.content : '',
                'Accept': 'application/json',
            },
        }).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (data) {
                if (! response.ok) {
                    throw new Error(data.message || 'התמלול נכשל.');
                }

                return data;
            });
        }).then(function (data) {
            if (! data.text) {
                announce('לא נשמעו מילים בהקלטה.');

                return;
            }

            insert(data.text);
            announce('התמלול הוכנס לשדה. קראו ותקנו לפני שליחה.');
        }).catch(function (e) {
            // נפילה אמיתית, ולא הנחה מראש: מכאן והלאה בדף הזה מכתיבים דרך
            // הדפדפן, ואומרים את זה — הכתבה שמפסיקה לעבוד בלי הסבר נראית
            // כמו תקלה במיקרופון.
            if (browserAvailable && useServer) {
                useServer = false;
                announce((e.message || 'התמלול נכשל') + ' — ממשיכים עם התמלול של הדפדפן.');
            } else {
                announce(e.message || 'התמלול נכשל. אפשר להקליד במקום.');
            }
        }).finally(function () {
            working = false;
            paint();
        });
    }

    /* ── משותף ───────────────────────────────────────────────────────── */

    function toggle() {
        if (! field || working) {
            return;
        }

        if (useServer) {
            listening ? stopRecording() : startRecording();

            return;
        }

        if (listening) {
            engine().stop();

            return;
        }

        try {
            engine().start();
            listening = true;
            announce('מקליט. לחצו שוב לעצירה.');
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

    // עזיבת הדף באמצע הקלטה משאירה את המיקרופון פתוח בלי זה.
    window.addEventListener('pagehide', function () {
        if (timer) { clearInterval(timer); timer = null; }
        releaseMicrophone();
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
