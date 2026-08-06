<?php

declare(strict_types=1);

/** @var string $checkUrl */
?>
<div class="card card-flush h-100">
	<div class="card-header pt-5"><h3 class="card-title fw-bold">서버 요구 사항</h3></div>
	<div class="card-body pt-0 fs-7 text-gray-700">
		<ul class="mb-4 ps-4">
			<li>PHP <code>zip</code> 확장 (xlsx 파싱)</li>
			<li>Python 3 + <code>msoffcrypto-tool</code> (암호 해제)</li>
		</ul>
		<p class="mb-2">설치 예:</p>
		<pre class="bg-light rounded p-3 fs-8 mb-4">sudo dnf install -y php-zip
sudo -u apache python3 -m pip install --user msoffcrypto-tool</pre>
		<a class="fw-semibold" href="<?= htmlspecialchars($checkUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Python·zip 환경 진단</a>
	</div>
</div>
