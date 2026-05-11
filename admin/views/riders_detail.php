<?php

declare(strict_types=1);

require_once INC_PATH . '/mock_riders.php';

$riderId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$catalog = mock_riders_by_id();
$seedRider = ($riderId !== '' && isset($catalog[$riderId])) ? $catalog[$riderId] : null;
$riderSeedJson = json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

$listUrl = admin_url('riders/list');
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0" id="kt_rider_detail_title">
				<?php if ($seedRider !== null) : ?>
					라이더 상세 — <?= htmlspecialchars((string) $seedRider['name'], ENT_QUOTES, 'UTF-8') ?>
				<?php else : ?>
					라이더 상세
				<?php endif; ?>
			</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted">
					<a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a>
				</li>
				<li class="breadcrumb-item">
					<span class="bullet bg-gray-500 w-5px h-2px"></span>
				</li>
				<li class="breadcrumb-item text-muted">라이더</li>
				<li class="breadcrumb-item">
					<span class="bullet bg-gray-500 w-5px h-2px"></span>
				</li>
				<li class="breadcrumb-item text-gray-900">상세</li>
			</ul>
		</div>
		<div class="d-flex gap-2 flex-wrap">
			<a href="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">
				<i class="ki-duotone ki-left fs-3"><span class="path1"></span><span class="path2"></span></i>
				목록으로
			</a>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<?php if ($seedRider !== null) :
	    $rider = $seedRider;
	    $stClass = mock_rider_status_badge_class((string) $rider['status']);
	    $kycClass = match ((string) $rider['kyc_status']) {
	        'verified' => 'success',
	        'pending' => 'warning',
	        default => 'primary',
	    };
	    ?>

	<div class="alert alert-dismissible bg-light-primary d-flex flex-column flex-sm-row p-5 mb-8">
		<i class="ki-duotone ki-badge fs-2hx text-primary me-4 mb-5 mb-sm-0"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span><span class="path5"></span></i>
		<div class="fs-7 text-gray-800">
			<strong>목업</strong>입니다. 아래 정보는 정산·출금·차감 화면과 동일한 도메인을 가정한 샘플이며, 저장 버튼은 동작하지 않습니다.
		</div>
	</div>

	<div class="row g-6 mb-8">
		<div class="col-12">
			<div class="card card-flush border border-gray-300 border-dashed">
				<div class="card-header pt-5">
					<h3 class="card-title fw-bold">앱 로그인 정보 (목업)</h3>
					<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">라이더 앱 접속용 ID · 비밀번호는 실서비스에서 반드시 해시 저장</span>
				</div>
				<div class="card-body pt-0 row g-6">
					<div class="col-md-6">
						<span class="text-gray-500 fw-semibold fs-7 d-block">로그인 ID</span>
						<span class="text-gray-900 fw-bold fs-4 font-monospace"><?= htmlspecialchars((string) ($rider['login_id'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></span>
					</div>
					<div class="col-md-6">
						<span class="text-gray-500 fw-semibold fs-7 d-block">비밀번호</span>
						<span class="text-gray-900 fw-bold font-monospace">••••••••</span>
						<div class="form-text">시드 데이터는 앱에서 별도 관리되는 것으로 가정합니다.</div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="row g-6 g-xl-9 mb-8">
		<div class="col-xl-4">
			<div class="card card-flush h-xl-100">
				<div class="card-body d-flex flex-column text-center pt-10 pb-15">
					<div class="symbol symbol-100px symbol-circle mb-7 mx-auto bg-light-primary">
						<span class="symbol-label fs-2x fw-bold text-primary"><?php
						    $nm = (string) $rider['name'];
						    $initial = function_exists('mb_substr') ? mb_substr($nm, 0, 1, 'UTF-8') : substr($nm, 0, 1);
						    echo htmlspecialchars($initial, ENT_QUOTES, 'UTF-8');
						    ?></span>
					</div>
					<span class="fs-2 fw-bold text-gray-900"><?= htmlspecialchars((string) $rider['name'], ENT_QUOTES, 'UTF-8') ?></span>
					<span class="text-gray-500 fs-6 fw-semibold mt-1"><?= htmlspecialchars((string) $rider['id'], ENT_QUOTES, 'UTF-8') ?></span>
					<div class="mt-5 d-flex flex-wrap justify-content-center gap-2">
						<span class="badge badge-light-<?= htmlspecialchars($stClass, ENT_QUOTES, 'UTF-8') ?> fs-7"><?= htmlspecialchars((string) $rider['status_label'], ENT_QUOTES, 'UTF-8') ?></span>
						<span class="badge badge-light-<?= htmlspecialchars($kycClass, ENT_QUOTES, 'UTF-8') ?> fs-7"><?= htmlspecialchars((string) $rider['kyc_label'], ENT_QUOTES, 'UTF-8') ?></span>
						<?php if (!empty($rider['withdrawal_hold'])) : ?>
						<span class="badge badge-light-danger fs-7">출금 보류</span>
						<?php endif; ?>
					</div>
					<div class="separator separator-dashed my-8"></div>
					<div class="row g-4 text-start w-100 px-5">
						<div class="col-6">
							<span class="text-gray-500 fs-7 fw-semibold d-block">이번 달 배달</span>
							<span class="text-gray-900 fw-bold fs-4"><?= number_format((int) $rider['orders_mtd']) ?></span>
						</div>
						<div class="col-6">
							<span class="text-gray-500 fs-7 fw-semibold d-block">지난주</span>
							<span class="text-gray-900 fw-bold fs-4"><?= number_format((int) $rider['orders_last_week']) ?></span>
						</div>
						<div class="col-6">
							<span class="text-gray-500 fs-7 fw-semibold d-block">평점(목업)</span>
							<span class="text-gray-900 fw-bold"><?= htmlspecialchars((string) $rider['rating_avg'], ENT_QUOTES, 'UTF-8') ?></span>
						</div>
						<div class="col-6">
							<span class="text-gray-500 fs-7 fw-semibold d-block">패널티</span>
							<span class="text-gray-900 fw-bold"><?= (int) $rider['penalty_points'] ?>점</span>
						</div>
					</div>
				</div>
			</div>
		</div>
		<div class="col-xl-8">
			<div class="row g-6">
				<div class="col-md-6">
					<div class="card card-flush h-100">
						<div class="card-header pt-5">
							<h3 class="card-title fw-bold">연락·계정</h3>
						</div>
						<div class="card-body pt-0 fs-6">
							<div class="mb-4">
								<span class="text-gray-500 fw-semibold d-block fs-7">휴대전화</span>
								<span class="text-gray-900"><?= htmlspecialchars((string) $rider['phone_masked'], ENT_QUOTES, 'UTF-8') ?></span>
							</div>
							<div class="mb-4">
								<span class="text-gray-500 fw-semibold d-block fs-7">이메일</span>
								<span class="text-gray-900"><?= htmlspecialchars((string) $rider['email'], ENT_QUOTES, 'UTF-8') ?></span>
							</div>
							<div class="mb-4">
								<span class="text-gray-500 fw-semibold d-block fs-7">생년월일</span>
								<span class="text-gray-900"><?= htmlspecialchars((string) $rider['birth_masked'], ENT_QUOTES, 'UTF-8') ?></span>
							</div>
							<div>
								<span class="text-gray-500 fw-semibold d-block fs-7">최근 앱 접속</span>
								<span class="text-gray-900"><?= htmlspecialchars((string) $rider['last_login_at'], ENT_QUOTES, 'UTF-8') ?></span>
							</div>
						</div>
					</div>
				</div>
				<div class="col-md-6">
					<div class="card card-flush h-100">
						<div class="card-header pt-5">
							<h3 class="card-title fw-bold">소속·배달</h3>
						</div>
						<div class="card-body pt-0 fs-6">
							<div class="mb-4">
								<span class="text-gray-500 fw-semibold d-block fs-7">팀</span>
								<span class="text-gray-900"><?= htmlspecialchars((string) $rider['team'], ENT_QUOTES, 'UTF-8') ?></span>
							</div>
							<div class="mb-4">
								<span class="text-gray-500 fw-semibold d-block fs-7">활동 지역</span>
								<span class="text-gray-900"><?= htmlspecialchars((string) $rider['address_short'], ENT_QUOTES, 'UTF-8') ?></span>
							</div>
							<div class="mb-4">
								<span class="text-gray-500 fw-semibold d-block fs-7">차량</span>
								<span class="text-gray-900"><?= htmlspecialchars((string) $rider['vehicle_type'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string) $rider['vehicle_number_masked'], ENT_QUOTES, 'UTF-8') ?></span>
							</div>
							<div class="mb-4">
								<span class="text-gray-500 fw-semibold d-block fs-7">가입일</span>
								<span class="text-gray-900"><?= htmlspecialchars((string) $rider['join_date'], ENT_QUOTES, 'UTF-8') ?></span>
							</div>
							<div>
								<span class="text-gray-500 fw-semibold d-block fs-7">최근 배달 완료</span>
								<span class="text-gray-900"><?= htmlspecialchars((string) $rider['last_delivery_at'], ENT_QUOTES, 'UTF-8') ?></span>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="row g-6 mb-8">
		<div class="col-xl-6">
			<div class="card card-flush h-100">
				<div class="card-header pt-5">
					<h3 class="card-title fw-bold">플랫폼 연동</h3>
					<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">배민·쿠팡 등 외부 ID (목업)</span>
				</div>
				<div class="card-body pt-0">
					<div class="table-responsive">
						<table class="table table-row-bordered align-middle gs-0 gy-3">
							<thead>
								<tr class="fw-bold text-muted fs-7">
									<th>플랫폼</th>
									<th>연동</th>
									<th>외부 ID</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($rider['platforms'] as $p) :
								    $conn = !empty($p['connected']);
								    ?>
								<tr>
									<td class="text-gray-900"><?= htmlspecialchars((string) $p['label'], ENT_QUOTES, 'UTF-8') ?></td>
									<td>
										<?php if ($conn) : ?>
										<span class="badge badge-light-success">연동됨</span>
										<?php else : ?>
										<span class="badge badge-light-dark">미연동</span>
										<?php endif; ?>
									</td>
									<td class="font-monospace fs-7 text-gray-800"><?= $conn && ($p['linked_id'] ?? '') !== '' ? htmlspecialchars((string) $p['linked_id'], ENT_QUOTES, 'UTF-8') : '—' ?></td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
		<div class="col-xl-6">
			<div class="card card-flush h-100">
				<div class="card-header pt-5">
					<h3 class="card-title fw-bold">정산 계좌</h3>
				</div>
				<div class="card-body pt-0 fs-6">
					<div class="mb-4">
						<span class="text-gray-500 fw-semibold d-block fs-7">은행</span>
						<span class="text-gray-900"><?= htmlspecialchars((string) $rider['bank_name'], ENT_QUOTES, 'UTF-8') ?></span>
					</div>
					<div class="mb-4">
						<span class="text-gray-500 fw-semibold d-block fs-7">계좌번호</span>
						<span class="text-gray-900 font-monospace"><?= htmlspecialchars((string) $rider['bank_account_masked'], ENT_QUOTES, 'UTF-8') ?></span>
					</div>
					<div>
						<span class="text-gray-500 fw-semibold d-block fs-7">예금주</span>
						<span class="text-gray-900"><?= htmlspecialchars((string) $rider['account_holder'], ENT_QUOTES, 'UTF-8') ?></span>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="row g-6 mb-8">
		<div class="col-xl-6">
			<div class="card card-flush h-100">
				<div class="card-header pt-5">
					<h3 class="card-title fw-bold">계약·보험</h3>
				</div>
				<div class="card-body pt-0 fs-6">
					<div class="mb-4">
						<span class="text-gray-500 fw-semibold d-block fs-7">본인인증 완료일</span>
						<span class="text-gray-900"><?= htmlspecialchars((string) $rider['id_verified_at'], ENT_QUOTES, 'UTF-8') ?></span>
					</div>
					<div class="mb-4">
						<span class="text-gray-500 fw-semibold d-block fs-7">계약 서명일</span>
						<span class="text-gray-900"><?= ($rider['contract_signed_at'] ?? '') !== '' ? htmlspecialchars((string) $rider['contract_signed_at'], ENT_QUOTES, 'UTF-8') : '—' ?></span>
					</div>
					<div class="mb-4">
						<span class="text-gray-500 fw-semibold d-block fs-7">보험</span>
						<span class="text-gray-900"><?= htmlspecialchars((string) $rider['insurance_name'], ENT_QUOTES, 'UTF-8') ?></span>
					</div>
					<div>
						<span class="text-gray-500 fw-semibold d-block fs-7">보험 만기</span>
						<span class="text-gray-900"><?= ($rider['insurance_expires'] ?? '') !== '' ? htmlspecialchars((string) $rider['insurance_expires'], ENT_QUOTES, 'UTF-8') : '—' ?></span>
					</div>
				</div>
			</div>
		</div>
		<div class="col-xl-6">
			<div class="card card-flush h-100">
				<div class="card-header pt-5">
					<h3 class="card-title fw-bold">관리자 메모</h3>
				</div>
				<div class="card-body pt-0">
					<textarea class="form-control form-control-solid" rows="6" readonly placeholder="메모 없음"><?= htmlspecialchars((string) ($rider['admin_memo'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
					<div class="d-flex justify-content-end mt-4">
						<button type="button" class="btn btn-sm btn-light-primary" disabled>메모 저장 (준비 중)</button>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="card card-flush mb-8">
		<div class="card-header pt-5">
			<h3 class="card-title fw-bold">가입 제출 서류 (목업)</h3>
			<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">신분증·통장 등은 「라이더 등록」 시 업로드한 건만 여기 저장됩니다</span>
		</div>
		<div class="card-body pt-0 fs-7 text-gray-700">
			<?php $riderDocs = $rider['documents'] ?? []; ?>
			<?php if (!empty($riderDocs) && is_array($riderDocs)) : ?>
			<div class="table-responsive">
				<table class="table table-row-bordered align-middle gs-0 gy-3">
					<thead>
						<tr class="fw-bold text-muted">
							<th>구분</th>
							<th>파일명</th>
							<th>크기</th>
							<th>등록 시각</th>
							<th>비고</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($riderDocs as $doc) : ?>
						<tr>
							<td><?= htmlspecialchars((string) ($doc['category'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
							<td class="font-monospace"><?= htmlspecialchars((string) ($doc['filename'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
							<td><?= isset($doc['size']) ? number_format((int) $doc['size']) . ' B' : '—' ?></td>
							<td><?= htmlspecialchars((string) ($doc['saved_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
							<td><?= htmlspecialchars((string) ($doc['note'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php else : ?>
			<p class="mb-0">이 라이더는 시드 샘플입니다. 등록 화면에서 파일을 첨부하면 <code class="fs-8">localStorage</code>에 보관되고, 신규 라이더 상세에서 미리보기·메타를 확인할 수 있습니다.</p>
			<?php endif; ?>
		</div>
	</div>

	<div class="card card-flush">
		<div class="card-header align-items-center py-5">
			<div class="card-title">
				<h3 class="fw-bold m-0">최근 활동</h3>
				<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">배달·정산·조치 이력 샘플</span>
			</div>
		</div>
		<div class="card-body pt-0">
			<div class="table-responsive">
				<table class="table table-row-bordered table-row-gray-300 align-middle gs-0 gy-3">
					<thead>
						<tr class="fw-bold text-muted">
							<th class="min-w-140px">일시</th>
							<th class="min-w-100px">유형</th>
							<th>내용</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($rider['recent_activity'] as $ev) : ?>
						<tr>
							<td class="text-gray-800"><?= htmlspecialchars((string) $ev['at'], ENT_QUOTES, 'UTF-8') ?></td>
							<td><span class="badge badge-light"><?= htmlspecialchars((string) $ev['type'], ENT_QUOTES, 'UTF-8') ?></span></td>
							<td class="text-gray-700"><?= htmlspecialchars((string) $ev['detail'], ENT_QUOTES, 'UTF-8') ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<div class="d-flex flex-wrap justify-content-end gap-3 mt-8 mb-4">
		<button type="button" class="btn btn-light" disabled>상태 변경 (준비 중)</button>
		<button type="button" class="btn btn-light-primary" disabled>알림 발송 (준비 중)</button>
	</div>

	<?php elseif ($riderId === '') : ?>
	<div class="alert alert-warning d-flex align-items-center p-5 mb-8">
		<i class="ki-duotone ki-information-5 fs-2hx text-warning me-4"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
		<div class="d-flex flex-column">
			<span class="fw-bold">라이더 ID가 없습니다.</span>
			<span class="fs-7 text-gray-700 mt-1">목록에서 상세를 눌러 주세요.</span>
			<a href="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light-primary align-self-start mt-3">라이더 목록</a>
		</div>
	</div>
	<?php else : ?>
	<div id="rider_client_detail_mount" data-rider-id="<?= htmlspecialchars($riderId, ENT_QUOTES, 'UTF-8') ?>"></div>
	<script>
	(function () {
		var mount = document.getElementById('rider_client_detail_mount');
		if (!mount) return;
		var wantId = mount.getAttribute('data-rider-id') || '';
		var SEED = <?= $riderSeedJson ?>;
		var STORAGE_KEY = 'baedal_riders_custom';
		function getCustom() {
			try { return JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}'); } catch (e) { return {}; }
		}
		function merged() { return Object.assign({}, SEED, getCustom()); }
		var r = merged()[wantId];
		var titleEl = document.getElementById('kt_rider_detail_title');
		if (!r) {
			mount.innerHTML = '<div class="alert alert-warning p-5 mb-0"><div class="fw-bold">라이더를 찾을 수 없습니다.</div><p class="fs-7 mt-2 mb-0">URL의 id가 올바른지 확인하거나 목록에서 등록·시드 데이터를 확인하세요.</p><a href="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light-primary mt-4">라이더 목록</a></div>';
			return;
		}
		if (titleEl) titleEl.textContent = '라이더 상세 — ' + r.name;

		function esc(s) {
			var d = document.createElement('div');
			d.textContent = s == null ? '' : String(s);
			return d.innerHTML;
		}
		var stMap = { active: 'success', suspended: 'danger', leave_request: 'warning', offboarded: 'dark' };
		var kycMap = { verified: 'success', pending: 'warning' };
		var st = stMap[r.status] || 'primary';
		var kyc = kycMap[r.kyc_status] || 'primary';
		var nm = String(r.name || '');
		var initial = nm.length ? nm.charAt(0) : '?';
		var pwdBlock = r.mock_registered && r.password_plain
			? '<div class="alert alert-danger fs-7 mb-0"><strong>목업 평문 비밀번호:</strong> <code class="user-select-all">' + esc(r.password_plain) + '</code></div>'
			: '<span class="text-gray-900 fw-bold font-monospace">••••••••</span><div class="form-text">시드 라이더와 동일하게 앱에서 관리 가정</div>';

		var platRows = (r.platforms || []).map(function (p) {
			var conn = !!p.connected;
			return '<tr><td class="text-gray-900">' + esc(p.label) + '</td><td>' + (conn ? '<span class="badge badge-light-success">연동됨</span>' : '<span class="badge badge-light-dark">미연동</span>') + '</td><td class="font-monospace fs-7">' + (conn && p.linked_id ? esc(p.linked_id) : '—') + '</td></tr>';
		}).join('');

		var actRows = (r.recent_activity || []).map(function (ev) {
			return '<tr><td class="text-gray-800">' + esc(ev.at) + '</td><td><span class="badge badge-light">' + esc(ev.type) + '</span></td><td class="text-gray-700">' + esc(ev.detail) + '</td></tr>';
		}).join('');

		var docs = r.documents || [];
		var docCard;
		if (!docs.length) {
			docCard =
				'<div class="card card-flush mb-8"><div class="card-header pt-5"><h3 class="card-title fw-bold">가입 제출 서류 (목업)</h3><span class="text-gray-500 fs-7 fw-semibold d-block mt-1">등록 시 첨부한 서류가 없습니다.</span></div><div class="card-body pt-0 fs-7 text-gray-700"><p class="mb-0">라이더 등록 화면에서 파일을 첨부하면 여기에 메타·미리보기(작은 이미지)가 표시됩니다.</p></div></div>';
		} else {
			var docRows = docs
				.map(function (doc) {
					var sz = doc.size != null ? Number(doc.size).toLocaleString('ko-KR') + ' B' : '—';
					var pu = doc.preview_data_url;
					var prev =
						typeof pu === 'string' && pu.indexOf('data:image/') === 0 ? pu.replace(/"/g, '&quot;') : '';
					var prevCell = prev
						? '<img src="' + prev + '" alt="" class="rounded border" style="max-height:120px;max-width:220px"/>'
						: '<span class="text-muted">—</span>';
					return (
						'<tr><td>' +
						esc(doc.category) +
						'</td><td class="font-monospace">' +
						esc(doc.filename) +
						'</td><td>' +
						esc(sz) +
						'</td><td>' +
						esc(doc.saved_at) +
						'</td><td>' +
						esc(doc.note || '') +
						'</td><td class="text-center">' +
						prevCell +
						'</td></tr>'
					);
				})
				.join('');
			docCard =
				'<div class="card card-flush mb-8"><div class="card-header pt-5"><h3 class="card-title fw-bold">가입 제출 서류 (목업)</h3><span class="text-gray-500 fs-7 fw-semibold d-block mt-1">신분증·통장 등은 「라이더 등록」 시 업로드한 건만 여기 저장됩니다</span></div><div class="card-body pt-0"><div class="table-responsive"><table class="table table-row-bordered align-middle gs-0 gy-3"><thead><tr class="fw-bold text-muted"><th>구분</th><th>파일명</th><th>크기</th><th>등록 시각</th><th>비고</th><th class="min-w-125px">미리보기</th></tr></thead><tbody>' +
				docRows +
				'</tbody></table></div></div></div>';
		}

		var hold = r.withdrawal_hold ? '<span class="badge badge-light-danger fs-7">출금 보류</span>' : '';

		mount.innerHTML =
			'<div class="alert alert-dismissible bg-light-primary d-flex p-5 mb-8"><div class="fs-7 text-gray-800"><strong>목업</strong> · 이 라이더는 <strong>등록(로컬 저장)</strong>으로 추가된 경우 비밀번호가 평문으로 표시될 수 있습니다.</div></div>' +
			'<div class="row g-6 mb-8"><div class="col-12"><div class="card card-flush border border-gray-300 border-dashed"><div class="card-header pt-5"><h3 class="card-title fw-bold">앱 로그인 정보 (목업)</h3></div><div class="card-body pt-0 row g-6"><div class="col-md-6"><span class="text-gray-500 fs-7 d-block">로그인 ID</span><span class="fw-bold fs-4 font-monospace text-gray-900">' + esc(r.login_id) + '</span></div><div class="col-md-6"><span class="text-gray-500 fs-7 d-block">비밀번호</span>' + pwdBlock + '</div></div></div></div></div>' +
			'<div class="row g-6 g-xl-9 mb-8"><div class="col-xl-4"><div class="card card-flush h-xl-100"><div class="card-body text-center pt-10 pb-15"><div class="symbol symbol-100px symbol-circle mb-7 mx-auto bg-light-primary"><span class="symbol-label fs-2x fw-bold text-primary">' + esc(initial) + '</span></div><span class="fs-2 fw-bold text-gray-900">' + esc(r.name) + '</span><span class="text-gray-500 fs-6 fw-semibold mt-1 d-block">' + esc(r.id) + '</span><div class="mt-5 d-flex flex-wrap justify-content-center gap-2"><span class="badge badge-light-' + st + ' fs-7">' + esc(r.status_label) + '</span><span class="badge badge-light-' + kyc + ' fs-7">' + esc(r.kyc_label) + '</span>' + hold + '</div><div class="separator my-8"></div><div class="row g-4 text-start w-100 px-5"><div class="col-6"><span class="text-gray-500 fs-7 fw-semibold d-block">이번 달 배달</span><span class="fw-bold fs-4">' + esc(String(r.orders_mtd)) + '</span></div><div class="col-6"><span class="text-gray-500 fs-7 fw-semibold d-block">지난주</span><span class="fw-bold fs-4">' + esc(String(r.orders_last_week)) + '</span></div><div class="col-6"><span class="text-gray-500 fs-7 fw-semibold d-block">평점</span><span class="fw-bold">' + esc(String(r.rating_avg)) + '</span></div><div class="col-6"><span class="text-gray-500 fs-7 fw-semibold d-block">패널티</span><span class="fw-bold">' + esc(String(r.penalty_points)) + '점</span></div></div></div></div></div>' +
			'<div class="col-xl-8"><div class="row g-6"><div class="col-md-6"><div class="card card-flush h-100"><div class="card-header pt-5"><h3 class="card-title fw-bold">연락·계정</h3></div><div class="card-body pt-0 fs-6"><div class="mb-4"><span class="text-gray-500 fs-7 d-block">휴대전화</span><span>' + esc(r.phone_masked) + '</span></div><div class="mb-4"><span class="text-gray-500 fs-7 d-block">이메일</span><span>' + esc(r.email) + '</span></div><div class="mb-4"><span class="text-gray-500 fs-7 d-block">생년월일</span><span>' + esc(r.birth_masked) + '</span></div><div><span class="text-gray-500 fs-7 d-block">최근 앱 접속</span><span>' + esc(r.last_login_at) + '</span></div></div></div></div><div class="col-md-6"><div class="card card-flush h-100"><div class="card-header pt-5"><h3 class="card-title fw-bold">소속·배달</h3></div><div class="card-body pt-0 fs-6"><div class="mb-4"><span class="text-gray-500 fs-7 d-block">팀</span><span>' + esc(r.team) + '</span></div><div class="mb-4"><span class="text-gray-500 fs-7 d-block">활동 지역</span><span>' + esc(r.address_short) + '</span></div><div class="mb-4"><span class="text-gray-500 fs-7 d-block">차량</span><span>' + esc(r.vehicle_type) + ' · ' + esc(r.vehicle_number_masked) + '</span></div><div class="mb-4"><span class="text-gray-500 fs-7 d-block">가입일</span><span>' + esc(r.join_date) + '</span></div><div><span class="text-gray-500 fs-7 d-block">최근 배달</span><span>' + esc(r.last_delivery_at) + '</span></div></div></div></div></div></div></div>' +
			'<div class="row g-6 mb-8"><div class="col-xl-6"><div class="card card-flush h-100"><div class="card-header pt-5"><h3 class="card-title fw-bold">플랫폼 연동</h3></div><div class="card-body pt-0"><table class="table table-row-bordered align-middle gs-0 gy-3"><thead><tr class="fw-bold text-muted fs-7"><th>플랫폼</th><th>연동</th><th>외부 ID</th></tr></thead><tbody>' + platRows + '</tbody></table></div></div></div><div class="col-xl-6"><div class="card card-flush h-100"><div class="card-header pt-5"><h3 class="card-title fw-bold">정산 계좌</h3></div><div class="card-body pt-0 fs-6"><div class="mb-4"><span class="text-gray-500 fs-7 d-block">은행</span><span>' + esc(r.bank_name) + '</span></div><div class="mb-4"><span class="text-gray-500 fs-7 d-block">계좌번호</span><span class="font-monospace">' + esc(r.bank_account_masked) + '</span></div><div><span class="text-gray-500 fs-7 d-block">예금주</span><span>' + esc(r.account_holder) + '</span></div></div></div></div></div>' +
			'<div class="row g-6 mb-8"><div class="col-xl-6"><div class="card card-flush h-100"><div class="card-header pt-5"><h3 class="card-title fw-bold">계약·보험</h3></div><div class="card-body pt-0 fs-6"><div class="mb-4"><span class="text-gray-500 fs-7 d-block">본인인증</span><span>' + esc(r.id_verified_at) + '</span></div><div class="mb-4"><span class="text-gray-500 fs-7 d-block">계약 서명</span><span>' + esc(r.contract_signed_at || '—') + '</span></div><div class="mb-4"><span class="text-gray-500 fs-7 d-block">보험</span><span>' + esc(r.insurance_name) + '</span></div><div><span class="text-gray-500 fs-7 d-block">만기</span><span>' + esc(r.insurance_expires || '—') + '</span></div></div></div></div><div class="col-xl-6"><div class="card card-flush h-100"><div class="card-header pt-5"><h3 class="card-title fw-bold">관리자 메모</h3></div><div class="card-body pt-0"><textarea class="form-control form-control-solid" rows="6" readonly>' + esc(r.admin_memo || '') + '</textarea></div></div></div></div>' +
			docCard +
			'<div class="card card-flush mb-8"><div class="card-header py-5"><h3 class="fw-bold m-0">최근 활동</h3></div><div class="card-body pt-0"><table class="table table-row-bordered gs-0 gy-3"><thead><tr class="fw-bold text-muted"><th>일시</th><th>유형</th><th>내용</th></tr></thead><tbody>' + actRows + '</tbody></table></div></div>' +
			'<div class="d-flex justify-content-end gap-3 mb-4"><button type="button" class="btn btn-light" disabled>상태 변경 (준비 중)</button><button type="button" class="btn btn-light-primary" disabled>알림 발송 (준비 중)</button></div>';
	})();
	</script>
	<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>
