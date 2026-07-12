<div dir="rtl" style="font-family: Arial, sans-serif; font-size: 15px; line-height: 1.7; color: #1f2937;">
    @if ($logo = \App\Support\Branding::logoUrl())
        <img src="{{ $logo }}" alt="לוגו" style="max-height: 64px; margin-bottom: 16px;">
    @endif
    <p>שלום {{ $name }},</p>
    <p>תודה על פתיחת הכרטיס אצלנו! 🎉</p>
    <p>מצורף כרטיס הלקוח החתום שלך (PDF) לתיעוד. הצוות שלנו כבר על זה, וניצור קשר להמשך.</p>
    <p>בברכה,<br>צוות מולטי דיגיטל</p>
</div>
