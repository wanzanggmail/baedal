"use strict";

var KTLandingPage = function () {
	var initTyped = function () {
		if (!document.querySelector("#kt_landing_hero_text")) return;
		if (typeof Typed === "undefined") return;
		new Typed("#kt_landing_hero_text", {
			strings: [
				"라이더 모집",
				"최저 수수료 · 낮은 이자",
				"배민 · 쿠팡이츠 파트너",
			],
			typeSpeed: 55,
			backSpeed: 35,
			loop: true,
		});
	};

	return {
		init: function () {
			initTyped();
		},
	};
}();

if (typeof module !== "undefined") {
	module.exports = KTLandingPage;
}

KTUtil.onDOMContentLoaded(function () {
	KTLandingPage.init();
});
