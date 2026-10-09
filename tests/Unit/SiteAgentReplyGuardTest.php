<?php

namespace Tests\Unit;

use App\Services\SiteAgent\SiteAgentConversation;
use App\Services\SiteAgent\SiteAgentReplyGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SiteAgentReplyGuardTest extends TestCase
{
    #[DataProvider('approvalInvitations')]
    public function test_it_identifies_unbacked_approval_invitations_in_model_output(string $reply): void
    {
        $this->assertTrue((new SiteAgentReplyGuard)->asksForApproval($reply));
    }

    public static function approvalInvitations(): array
    {
        return [
            'reported transcript' => ['בוקר טוב ריקי, אני מכין את השינוי בדף הבית (עמוד "login"): להחליף את: "איזה כייף שבאת" ב: "כמה נחמד שבאת" לביצוע השיבו "כן". לביטול — "לא".'],
            'second reported transcript' => ['בוקר טוב ריקי, תודה על הסבלנות. זיהיתי את העמוד (עמוד הבית, מזהה 43) ואת הטקסט המדויק שמופיע בו: **"איזה כייף שבאת"**. אני מכין את השינוי להחלפת הטקסט ל: **"כמה נחמד שבאת"**. לביצוע השינוי, השיבי **"כן"**. לביטול, השיבי **"לא"**.'],
            'reported virtual product invitation' => ['המוצר שיצרנו כרגע ("מוצר דוגמה") מוגדר כפיזי כברירת מחדל, כיוון שלא ציינו אחרת בעת יצירתו. תרצי שאעדכן אותו למוצר וירטואלי (ללא משלוח)?'],
            'reported substitute rename invitation' => ['האם תרצי שאעדכן את שם המוצר ל"מוצר דוגמה (וירטואלי)"?'],
            'write invitation after paragraph' => ["המוצר מופיע בקטלוג\nתרצי שאעדכן אותו למוצר וירטואלי?"],
            'masculine write invitation' => ['תרצה שאשנה את שם המוצר?'],
            'plural write invitation' => ['תרצו שנגדיר אותו כמוצר וירטואלי?'],
            'reported prepare invitation' => ['מצאתי את עמוד האודות. האם תרצי שאגיש הצעה לשינוי שמו ל"הסיפור שלנו"?'],
            'reported trash invitation' => ['האם תרצי שאעביר אותו לפח? (הפעולה הפיכה)'],
            'reported caption invitation' => ['האם תרצי שאוסיף את הכיתוב "חולצה חדשה" לשדה הכיתוב של תמונה זו?'],
            'reported optimole invitation' => ['האם תרצי שאאשר את השינויים האלו?'],
            'prepare modified offer' => ['האם תרצי שאגיש את השינויים האלו לאישור?'],
            'English prepare invitation' => ['Would you like me to prepare a proposal for this change?'],
            'create consent shortcut' => ['האם ליצור את המוצר כעת? (כן/לא)'],
            'personal consent' => ['האם את מאשרת להשהות את המנוי הזה לסטטוס on-hold?'],
            'fictional page handoff' => ['העברתי את הבקשה להחלפת הטקסט לטיפול המערכת. העורך יציג תצוגה מקדימה.'],
            'explicit consent before preparation' => ['האם להוסיף פריט חדש? אשמח לאישורך לפני שאגיש את הפעולה.'],
            'submit proposal question' => ['האם להגיש את ההצעה הזו לאישור?'],
            'create enrolment proposal' => ['האם תרצה שאצור הצעה להוספתה לקבוצה?'],
            'propose enrolment' => ['האם תרצי שאציע לרשום אותה לקורס?'],
            'propose theme switch' => ['האם תרצי שאציע להחליף את ערכת הנושא?'],
            'explicit owner pronoun' => ['האם את רוצה שאגיש הצעה להסרת הווידגט?'],
            'polite feminine directive' => ['לביצוע השינוי, אנא השיבי "כן".'],
            'polite shorthand feminine directive' => ['לביצוע השינוי, אנא השבי "כן". לביטול — "לא".'],
            'polite quoted prefix' => ['לביצוע, אנא השיבי ב"כן".'],
            'fictional layout handoff' => ['האם תרצי שאשלח את הבקשה הזו כעת לביצוע?'],
            'canonical' => [SiteAgentConversation::CONFIRM_PROMPT],
            'Hebrew smart quotes' => ['לביצוע השיבו ״כן״. לביטול — ״לא״.'],
            'English smart quotes' => ['לאישור כתבי “כן” או “לא”.'],
            'apostrophes' => ["כתוב 'כן' לביצוע השינוי."],
            'bold and newline' => ["לאישור\n**כתבו** **כן**"],
            'direction marks' => ["לביצוע השיבו \u{200F}״כן״"],
            'short approval' => ['לאישור כתבו כן'],
            'reply approval' => ['לאישור השיבו כן'],
            'quoted reply connector' => ['לביצוע השיבו ב״כן״ או ב״לא״.'],
            'send approval' => ['לאישור שלחו כן'],
            'approval with object' => ['לאישור ההצעה, כתבי ״כן״.'],
            'purpose after reply' => ['כתבי כאן כן לביצוע.'],
            'permission question' => ['האם לאשר את השינוי?'],
            'execute question' => ['האם לבצע את העדכון'],
            'continue question' => ['האם להמשיך בשינוי?'],
            'continue without punctuation' => ['האם להמשיך בשינוי'],
            'continue after preparation offer' => ['אשמח לבדוק את רשימת התוספים ולהכין עבורך הצעה. האם תרצי שאמשיך בכך?'],
            'approval plural' => ['מאשרים את השינוי?'],
            'approval feminine' => ['מאשרת?'],
            'execution shortcut' => ['לבצע?'],
            'proceed shortcut' => ['להתקדם עם ההצעה?'],
            'personal permission' => ['תרצי שאבצע את השינוי?'],
            'button' => ['לביצוע לחצו על הכפתור "כן".'],
            'English reply' => ['Reply YES to confirm.'],
            'English smart reply' => ['Reply “yes” to confirm, or “no” to cancel.'],
            'English type' => ['Type yes to approve.'],
            'English prefix' => ['To approve, reply yes.'],
            'English request' => ['Please confirm the change.'],
            'English direct request' => ['Approve this update.'],
            'English bare confirmation' => ['Confirm?'],
            'English question' => ['Shall I proceed?'],
            'English polite question' => ['Would you like me to proceed?'],
            'English specific question' => ['Can I apply this change?'],
            'English approval question' => ['Do you approve this change?'],
        ];
    }

    #[DataProvider('ordinaryReplies')]
    public function test_it_preserves_ordinary_questions_read_answers_and_history(string $reply): void
    {
        $this->assertFalse((new SiteAgentReplyGuard)->asksForApproval($reply));
    }

    public static function ordinaryReplies(): array
    {
        return [
            'greeting' => ['שלום ריקי, במה אוכל לעזור?'],
            'page clarification' => ['האם הכוונה לעמוד הבית או לעמוד צור קשר?'],
            'trash target clarification' => ['איזה עמוד תרצי שאעביר לפח?'],
            'proposal options clarification' => ['איזה שינוי תרצי שאכין עבורך?'],
            'ordinary submission history' => ['הבקשה הקודמת הוגשה אתמול ואושרה.'],
            'which page to prepare' => ['באיזה עמוד להכין שינוי?'],
            'which product to propose' => ['לאיזה מוצר ליצור הצעה?'],
            'target understanding confirmation' => ['האם הכוונה לעמוד הבית? אשמח לאישורך שהבנתי את היעד.'],
            'text clarification' => ['איזה טקסט להחליף ובמה?'],
            'missing action clarification' => ['איזו פעולה רצית לבצע?'],
            'missing new text' => ['מה לכתוב במקום "איזה כייף"?'],
            'which product to update' => ['איזה מוצר תרצי שאעדכן?'],
            'which field to update' => ['איזה שדה תרצי שאעדכן במוצר?'],
            'which name to use' => ['באיזה שם תרצי שאשמור את המוצר?'],
            'which product after sentence' => ['מצאתי כמה מוצרים. איזה מוצר תרצי שאעדכן?'],
            'what to update' => ['מה תרצי שאעדכן בשם המוצר?'],
            'how to update' => ['כיצד תרצי שאעדכן אותו?'],
            'where to update' => ['היכן תרצי שאעדכן את הטקסט?'],
            'quoted product name' => ['יש מוצר בשם "כן" במחיר 79 שקלים.'],
            'unquoted product name' => ['יש מוצר בשם כן.'],
            'negative proposal state' => ['אין הצעה לאישור. לא בוצע שינוי.'],
            'capability explanation' => ['שינויים דורשים אישור לפני ביצוע.'],
            'approval explanation' => ['כל שינוי באתר דורש הצעה ואישור חדש.'],
            'history' => ['השינוי הקודם אושר ובוצע אתמול. כרגע אין הצעה חדשה לאישור.'],
            'history answer' => ['השבת "כן" אתמול, וההזמנה סומנה כהושלמה.'],
            'declined history' => ['ההצעה הקודמת בוטלה לאחר שכתבת "לא".'],
            'ordinary yes no clarification' => ['האם לכלול תתי־קטגוריות? השיבו כן או לא.'],
            'quoted yes no clarification' => ['האם לכלול תתי־קטגוריות? השיבו "כן" או "לא".'],
            'smart quoted yes no clarification' => ['האם זה עמוד הבית? השיבו ״כן״ או ״לא״.'],
            'quoted feminine clarification near execution context' => ['לביצוע השינוי דרושים עוד פרטים. האם זה עמוד הבית? השיבי **"כן"** או **"לא"**.'],
            'bare yes instruction' => ['כתבו כן'],
            'bare quoted yes instruction' => ['כתבו "כן"'],
            'ordinary yes no question' => ['האם האתר משתמש ב־WooCommerce? אפשר לענות כן או לא.'],
            'read answer' => ['יש שלוש הזמנות בטיפול.'],
            'failure' => ['הקריאה לאתר נכשלה ולכן עדיין לא הוכנה הצעה לביצוע.'],
            'unsupported action' => ['לא ניתן לבצע מכאן החזר כספי.'],
            'English answer' => ['There is a product named "yes".'],
            'English explanation' => ['Site changes require approval before execution.'],
            'English no pending' => ['There is no change to approve.'],
            'English failed verification' => ['I cannot confirm the change was applied.'],
            'English history' => ['You approved the change yesterday.'],
            'English clarification' => ['Is this the home page? Reply yes or no.'],
            'English identity clarification' => ['Please confirm which page you mean.'],
            'English quoted clarification' => ['Is this the home page? Reply "yes" or "no".'],
            'English smart quoted clarification' => ['Is this the home page? Reply “yes” or “no”.'],
            'English bare yes instruction' => ['Reply yes.'],
            'empty' => [''],
        ];
    }

    public function test_a_long_non_approval_reply_is_bounded(): void
    {
        $this->assertFalse((new SiteAgentReplyGuard)->asksForApproval(str_repeat('מידע רגיל. ', 20000)));
    }

    #[DataProvider('supportClaims')]
    public function test_support_handoffs_need_a_real_capability(string $reply, bool $blocked): void
    {
        $this->assertSame($blocked, (new SiteAgentReplyGuard)->offersUnsupportedHandoff($reply));
    }

    public static function supportClaims(): array
    {
        return [
            ['אני מעביר את הפנייה לצוות התמיכה שלנו בכתובת support@example.test.', true],
            ['האם תרצי שאפנה לצוות התמיכה?', true],
            ['אפשר לערוך תוכן, או שתרצי שאפנה את הבקשה לצוות הטכני?', true],
            ['העברתי את הבקשה לצוות הטכני.', true],
            ['אני יכולה להעביר את הפנייה לצוות התמיכה.', true],
            ['הבקשה נשלחה לצוות התמיכה.', true],
            ['פתחתי עבורך קריאת שירות.', true],
            ['I have sent your request to support.', true],
            ['Would you like me to contact support?', true],
            ['Your request was forwarded to support.', true],
            ['אפשר לפנות בעצמך לצוות התמיכה בכתובת support@example.test.', false],
            ['לא העברתי את הבקשה לצוות התמיכה.', false],
            ['אין לי כלי לשליחת פנייה לתמיכה.', false],
            ['אני לא שולח פנייה לצוות התמיכה.', false],
            ['איני מעביר פניות לצוות התמיכה.', false],
            ['אפנה אותך לצוות התמיכה דרך פרטי הקשר.', false],
            ['הבקשה לא נשלחה לצוות התמיכה.', false],
            ['I cannot send a request to support.', false],
            ['Please contact support directly.', false],
        ];
    }
}
