<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="rtl">
<head>
<title>{{ config('app.name') }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<style>
/* Mobile.

   Most of our mail is read on a phone, and the layout it inherits is built
   around a 570px column. On a 360px screen that column does not shrink on its
   own: it is a table with a pixel width, so the client either scales the whole
   message down to unreadable or lets it scroll sideways. Every rule below is
   !important because the theme is INLINED into style attributes before sending
   — a plain rule here would lose to the inline one on every element.

   These live in the <style> block and not in the theme file for the same
   reason: an inliner cannot inline a media query, so the theme file can only
   describe the desktop case. */
@media only screen and (max-width: 600px) {
.wrapper, .content, .inner-body, .footer, .header {
width: 100% !important;
max-width: 100% !important;
min-width: 0 !important;
}

/* 32px on each side of a 360px screen leaves under 300px for the words. */
.content-cell {
padding: 20px 16px !important;
}

.footer {
padding: 0 16px !important;
}

/* Nothing may be wider than the screen: a table or an image that is pushes
   every line of text sideways with it. */
table, img {
max-width: 100% !important;
}

/* A long link or a Hebrew word with no break opportunity is the usual cause
   of a message that scrolls sideways on a phone. */
.content-cell, .content-cell p, .content-cell td, .footer p {
word-break: break-word !important;
overflow-wrap: break-word !important;
}

/* Comfortable on a phone held at arm's length — and at least 16px, below
   which iOS zooms the page in on its own. */
.content-cell p, .content-cell li, .content-cell td {
font-size: 16px !important;
line-height: 1.6 !important;
}

.content-cell h1 { font-size: 20px !important; }
.content-cell h2 { font-size: 18px !important; }

.subcopy {
margin-top: 18px !important;
padding-top: 18px !important;
}

/* A key/value table (the monitoring report) stacks: two columns inside 328px
   squeeze the value into a two-character column. */
.mo-kv td {
display: block !important;
width: 100% !important;
padding: 2px 0 !important;
}

.mo-kv tr {
display: block !important;
padding: 6px 0 !important;
border-bottom: 1px solid #e4e4e7 !important;
}

.mo-kv tr:last-child { border-bottom: 0 !important; }
}

/* A button is a tap target before it is a link: full width, and tall enough to
   hit without aiming. The border-box matters — this theme builds the button's
   padding out of thick borders, which would otherwise be added to the 100%. */
@media only screen and (max-width: 500px) {
.button {
width: 100% !important;
box-sizing: border-box !important;
text-align: center !important;
}
}

/* Hebrew is right-to-left: flip direction and alignment for the reading
   surfaces (body copy, content cell, subcopy). The logo header and the footer
   stay centered — forcing them right is what pushed the logo off to the side. */
body, .content-cell, .inner-body, .subcopy {
direction: rtl !important;
text-align: right !important;
}
.header, .footer {
direction: rtl !important;
text-align: center !important;
}
</style>
{!! $head ?? '' !!}
</head>
<body dir="rtl">

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
{!! $header ?? '' !!}

<!-- Email Body -->
<tr>
<td class="body" width="100%" cellpadding="0" cellspacing="0" style="border: hidden !important;">
<table class="inner-body" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<!-- Body content -->
<tr>
<td class="content-cell" dir="rtl" style="direction: rtl !important; text-align: right !important;">
{!! Illuminate\Mail\Markdown::parse($slot) !!}

{!! $subcopy ?? '' !!}
</td>
</tr>
</table>
</td>
</tr>

{!! $footer ?? '' !!}
</table>
</td>
</tr>
</table>
</body>
</html>
