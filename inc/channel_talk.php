<?php

declare(strict_types=1);

/**
 * 채널톡(Channel Talk) 상담 위젯 (2026-09-19 갑) — 홈페이지·관리자·라이더 앱 공통.
 *
 * 가이드: https://developers.channel.io/ko/articles/d27c51d1
 *
 * 로그인한 사람이면 **누가 문의했는지** 가 상담창에 바로 뜨도록 프로필을 실어 보낸다.
 * 비로그인(홈페이지)이면 익명으로 띄운다.
 *
 * 🔑 `pluginKey` 는 **원래 페이지 소스에 노출되는 공개 값**이다(비밀키가 아니다).
 *    그래도 채널을 바꿀 수 있게 `.env` 의 `CHANNEL_TALK_KEY` 로 덮어쓸 수 있게 해 둔다.
 *
 * 🔒 `CHANNEL_TALK_SECRET`(채널톡 「회원 인증」 켤 때 발급)이 .env 에 있으면 `memberHash` 를
 *    같이 보낸다 — 없으면 보내지 않는다. 인증을 켠 채널에서 해시가 없으면 부팅이 거부되고,
 *    반대로 인증을 안 켠 채널에 해시를 보내면 무시된다. 즉 **있을 때만 보내는 게 맞다**.
 *
 * 전화번호는 채널톡이 국제표기(+82…)를 기대하므로 010… → +8210… 으로 바꿔 보낸다.
 */

if (!function_exists('channel_talk_key')) {
    function channel_talk_key(): string
    {
        $k = trim((string) (getenv('CHANNEL_TALK_KEY') ?: ($_SERVER['CHANNEL_TALK_KEY'] ?? '')));

        return $k !== '' ? $k : '013d5df0-d524-481d-b182-3d0de69cbfa5';
    }

    /** 010-1234-5678 → +821012345678 (변환 못 하면 빈 문자열) */
    function channel_talk_mobile(string $phone): string
    {
        $d = preg_replace('/\D/', '', $phone) ?? '';
        if ($d === '') {
            return '';
        }
        if (str_starts_with($d, '82')) {
            return '+' . $d;
        }
        if (str_starts_with($d, '0')) {
            return '+82' . substr($d, 1);
        }

        return '+82' . $d;
    }

    /**
     * 부팅 옵션. 로그인 정보가 있으면 프로필을 채운다.
     *
     * @return array<string,mixed>
     */
    function channel_talk_boot_options(): array
    {
        $opt = ['pluginKey' => channel_talk_key()];

        $memberId = '';
        $profile  = [];

        if (function_exists('rider_current_user') && rider_current_user() !== null) {
            $u        = rider_current_user();
            $memberId = 'rider_' . (int) $u['id'];
            $profile  = [
                'name'         => (string) ($u['name'] ?? ''),
                'mobileNumber' => channel_talk_mobile((string) ($u['phone'] ?? '')),
                '구분'          => '라이더',
                '라이더코드'    => (string) ($u['rider_code'] ?? ''),
            ];
        } elseif (!empty($_SESSION['admin_auth'])) {
            $memberId = 'admin_' . (int) ($_SESSION['admin_id'] ?? 0);
            $profile  = [
                'name'      => (string) ($_SESSION['admin_name'] ?? ''),
                '구분'       => '관리자',
                '로그인ID'   => (string) ($_SESSION['admin_login_id'] ?? ''),
                '소속조직'   => (string) (function_exists('admin_org') ? (admin_org()['name'] ?? '') : ''),
            ];
        }

        if ($memberId !== '') {
            $opt['memberId'] = $memberId;
            $opt['profile']  = array_filter($profile, static fn ($v): bool => trim((string) $v) !== '');

            $secret = trim((string) (getenv('CHANNEL_TALK_SECRET') ?: ($_SERVER['CHANNEL_TALK_SECRET'] ?? '')));
            if ($secret !== '') {
                $opt['memberHash'] = hash_hmac('sha256', $memberId, $secret);
            }
        }

        return $opt;
    }
}

$channelTalkOptions = channel_talk_boot_options();
if (trim((string) $channelTalkOptions['pluginKey']) === '') {
    return;
}
?>
<!--begin::채널톡 상담-->
<script>
(function () {
	var w = window;
	if (w.ChannelIO) { return w.console.error('ChannelIO script included twice.'); }
	var ch = function () { ch.c(arguments); };
	ch.q = [];
	ch.c = function (args) { ch.q.push(args); };
	w.ChannelIO = ch;
	function l() {
		if (w.ChannelIOInitialized) { return; }
		w.ChannelIOInitialized = true;
		var s = document.createElement('script');
		s.type = 'text/javascript';
		s.async = true;
		s.src = 'https://cdn.channel.io/plugin/ch-plugin-web.js';
		var x = document.getElementsByTagName('script')[0];
		if (x.parentNode) { x.parentNode.insertBefore(s, x); }
	}
	if (document.readyState === 'complete') { l(); }
	else { w.addEventListener('DOMContentLoaded', l); w.addEventListener('load', l); }
})();

ChannelIO('boot', <?= json_encode($channelTalkOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
</script>
<!--end::채널톡 상담-->
