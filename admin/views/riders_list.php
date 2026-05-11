<?php

declare(strict_types=1);

require_once INC_PATH . '/mock_riders.php';

$riderSeedJson = json_encode(mock_riders_by_id(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$detailBaseJson = json_encode(admin_url('riders/detail'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">라이더 관리</h1>
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
				<li class="breadcrumb-item text-gray-900">목록</li>
			</ul>
		</div>
		<div class="d-flex gap-2 flex-wrap">
			<button type="button" class="btn btn-sm btn-primary fw-bold" data-bs-toggle="modal" data-bs-target="#kt_rider_register_modal" id="btn_rider_register_open">
				<i class="ki-duotone ki-user-tick fs-3"><span class="path1"></span><span class="path2"></span></i>
				라이더 등록
			</button>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<div class="alert alert-dismissible bg-light-primary d-flex flex-column flex-sm-row p-5 mb-8">
		<i class="ki-duotone ki-people fs-2hx text-primary me-4 mb-5 mb-sm-0"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span><span class="path5"></span></i>
		<div class="fs-7 text-gray-800">
			<strong>목업</strong>입니다. <strong>라이더 등록</strong>으로 추가한 건은 브라우저 <code class="fs-8">localStorage</code>에만 저장됩니다. 로그인 비밀번호·일부 서류 미리보기는 <strong>목업용</strong>이며, 실서비스에서는 서버 업로드·해시 저장이 필요합니다.
		</div>
	</div>

	<div class="card card-flush mb-8">
		<div class="card-body py-5">
			<div class="row g-4 align-items-end">
				<div class="col-md-3">
					<label class="form-label fw-semibold">검색</label>
					<input type="text" class="form-control form-control-solid" id="rider_filter_q" placeholder="이름, 라이더 ID, 로그인 ID, 전화" autocomplete="off" />
				</div>
				<div class="col-md-2">
					<label class="form-label fw-semibold">팀</label>
					<select class="form-select form-select-solid" id="rider_filter_team">
						<option value="" selected>전체</option>
						<option value="gangseo_a">강서남부 A조</option>
						<option value="gangseo_b">강서남부 B조</option>
						<option value="ydp">영등포</option>
						<option value="mapo">마포</option>
					</select>
				</div>
				<div class="col-md-2">
					<label class="form-label fw-semibold">상태</label>
					<select class="form-select form-select-solid" id="rider_filter_status">
						<option value="" selected>전체</option>
						<option value="active">활동 중</option>
						<option value="suspended">일시 정지</option>
						<option value="leave_request">휴·탈퇴 요청</option>
						<option value="offboarded">계약 종료</option>
					</select>
				</div>
				<div class="col-md-2">
					<label class="form-label fw-semibold">주 플랫폼</label>
					<select class="form-select form-select-solid" id="rider_filter_platform">
						<option value="" selected>전체</option>
						<option value="baemin">배민</option>
						<option value="coupang">쿠팡</option>
					</select>
				</div>
				<div class="col-md-3 text-md-end">
					<button type="button" class="btn btn-light-primary" id="rider_filter_apply">필터 적용</button>
				</div>
			</div>
		</div>
	</div>

	<div class="card card-flush">
		<div class="card-header align-items-center py-5">
			<div class="card-title">
				<h3 class="fw-bold m-0">라이더 목록</h3>
				<span class="text-gray-500 fs-7 fw-semibold d-block mt-1" id="rider_count_badge">총 —명 (목업)</span>
			</div>
		</div>
		<div class="card-body pt-0">
			<div class="table-responsive">
				<table class="table table-row-bordered table-row-gray-300 align-middle gs-0 gy-4" id="rider_table">
					<thead>
						<tr class="fw-bold text-muted">
							<th class="min-w-110px">라이더 ID</th>
							<th class="min-w-100px">로그인 ID</th>
							<th class="min-w-90px">이름</th>
							<th class="min-w-120px">연락처</th>
							<th class="min-w-120px">팀</th>
							<th class="min-w-80px">주 플랫폼</th>
							<th class="min-w-90px">차량</th>
							<th class="min-w-100px">상태</th>
							<th class="min-w-110px">가입일</th>
							<th class="min-w-130px">최근 접속</th>
							<th class="min-w-100px text-end">관리</th>
						</tr>
					</thead>
					<tbody id="rider_tbody"></tbody>
				</table>
			</div>
		</div>
	</div>

	<div class="modal fade" id="kt_rider_register_modal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered mw-750px">
			<div class="modal-content">
				<div class="modal-header">
					<h2 class="fw-bold">라이더 등록 (목업)</h2>
					<div class="btn btn-icon btn-sm btn-active-icon-primary" data-bs-dismiss="modal">
						<i class="ki-duotone ki-cross fs-1"><span class="path1"></span><span class="path2"></span></i>
					</div>
				</div>
				<div class="modal-body py-lg-8 px-lg-10">
					<form id="rider_register_form">
						<div class="alert alert-warning d-flex align-items-center p-4 mb-8">
							<span class="fs-7">비밀번호는 <strong>목업에서만</strong> 브라우저 저장소에 평문으로 들어갑니다. 운영 환경에서는 절대 이렇게 저장하지 않습니다.</span>
						</div>
						<div class="row g-6 mb-6">
							<div class="col-md-6">
								<label class="form-label required">앱 로그인 ID</label>
								<input type="text" class="form-control form-control-solid" id="reg_login_id" required maxlength="64" placeholder="영문·숫자 (예: rider_hong)" autocomplete="off" />
								<div class="form-text">앱에서 로그인할 때 사용하는 아이디입니다.</div>
							</div>
							<div class="col-md-6">
								<label class="form-label">시스템 라이더 ID (선택)</label>
								<input type="text" class="form-control form-control-solid" id="reg_rider_id_custom" maxlength="32" placeholder="비우면 R-REG-타임스탬프 자동" autocomplete="off" />
								<div class="form-text"><code>R-</code> 로 시작하는 고유 코드. 비우면 자동 부여.</div>
							</div>
						</div>
						<div class="row g-6 mb-6">
							<div class="col-md-6">
								<label class="form-label required">비밀번호</label>
								<input type="password" class="form-control form-control-solid" id="reg_password" required minlength="4" maxlength="128" autocomplete="new-password" />
							</div>
							<div class="col-md-6">
								<label class="form-label required">비밀번호 확인</label>
								<input type="password" class="form-control form-control-solid" id="reg_password_confirm" required minlength="4" maxlength="128" autocomplete="new-password" />
							</div>
						</div>
						<div class="separator my-8"></div>
						<h4 class="fw-bold mb-6">기본 프로필</h4>
						<div class="row g-6 mb-6">
							<div class="col-md-6">
								<label class="form-label required">이름</label>
								<input type="text" class="form-control form-control-solid" id="reg_name" required maxlength="40" />
							</div>
							<div class="col-md-6">
								<label class="form-label required">휴대전화</label>
								<input type="text" class="form-control form-control-solid" id="reg_phone" required maxlength="20" placeholder="01012345678" />
							</div>
						</div>
						<div class="row g-6 mb-6">
							<div class="col-md-6">
								<label class="form-label required">이메일</label>
								<input type="email" class="form-control form-control-solid" id="reg_email" required maxlength="120" />
							</div>
							<div class="col-md-6">
								<label class="form-label">생년월일 (선택)</label>
								<input type="text" class="form-control form-control-solid" id="reg_birth" placeholder="YYYY-MM-DD" maxlength="10" />
							</div>
						</div>
						<div class="row g-6 mb-6">
							<div class="col-md-4">
								<label class="form-label required">팀</label>
								<select class="form-select form-select-solid" id="reg_team" required>
									<option value="gangseo_a">강서남부 A조</option>
									<option value="gangseo_b">강서남부 B조</option>
									<option value="ydp">영등포</option>
									<option value="mapo">마포</option>
								</select>
							</div>
							<div class="col-md-4">
								<label class="form-label required">주 플랫폼</label>
								<select class="form-select form-select-solid" id="reg_platform" required>
									<option value="baemin">배달의민족</option>
									<option value="coupang">쿠팡이츠</option>
								</select>
							</div>
							<div class="col-md-4">
								<label class="form-label required">차량</label>
								<select class="form-select form-select-solid" id="reg_vehicle" required>
									<option value="오토바이">오토바이</option>
									<option value="전동킥보드">전동킥보드</option>
									<option value="자전거">자전거</option>
									<option value="도보">도보</option>
								</select>
							</div>
						</div>
						<div class="mb-6">
							<label class="form-label">활동 지역</label>
							<input type="text" class="form-control form-control-solid" id="reg_address" maxlength="80" placeholder="예: 서울 강서구" />
						</div>
						<div class="separator my-8"></div>
						<h4 class="fw-bold mb-2">관련 서류 (목업 업로드)</h4>
						<p class="text-gray-600 fs-7 mb-6">이미지는 용량이 작을 때만 브라우저에 미리보기용으로 저장됩니다. PDF·대용량 파일은 파일명·크기 등 메타만 저장됩니다.</p>
						<div class="row g-6 mb-6">
							<div class="col-md-6">
								<label class="form-label">신분증 사본</label>
								<input type="file" class="form-control form-control-solid" id="reg_doc_id" accept="image/*,.pdf" />
							</div>
							<div class="col-md-6">
								<label class="form-label">통장 사본</label>
								<input type="file" class="form-control form-control-solid" id="reg_doc_bank" accept="image/*,.pdf" />
							</div>
						</div>
						<div class="row g-6 mb-6">
							<div class="col-md-6">
								<label class="form-label">운전면허증 (또는 배달 자격 서류)</label>
								<input type="file" class="form-control form-control-solid" id="reg_doc_license" accept="image/*,.pdf" />
							</div>
							<div class="col-md-6">
								<label class="form-label">추가 서류 (복수 선택)</label>
								<input type="file" class="form-control form-control-solid" id="reg_doc_extra" accept="image/*,.pdf" multiple />
							</div>
						</div>
						<h4 class="fw-bold mb-6">정산 계좌</h4>
						<div class="row g-6 mb-8">
							<div class="col-md-4">
								<label class="form-label required">은행</label>
								<input type="text" class="form-control form-control-solid" id="reg_bank_name" required maxlength="30" />
							</div>
							<div class="col-md-4">
								<label class="form-label required">예금주</label>
								<input type="text" class="form-control form-control-solid" id="reg_account_holder" required maxlength="40" />
							</div>
							<div class="col-md-4">
								<label class="form-label required">계좌번호</label>
								<input type="text" class="form-control form-control-solid" id="reg_bank_account" required maxlength="30" />
							</div>
						</div>
						<div class="d-flex justify-content-end gap-3">
							<button type="button" class="btn btn-light" data-bs-dismiss="modal">취소</button>
							<button type="submit" class="btn btn-primary">등록 저장</button>
						</div>
					</form>
				</div>
			</div>
		</div>
	</div>

	<script>
	(function () {
		var RIDER_SEED = <?= $riderSeedJson ?>;
		var DETAIL_BASE = <?= $detailBaseJson ?>;
		var STORAGE_KEY = 'baedal_riders_custom';

		var TEAM_LABEL = { gangseo_a: '강서남부 A조', gangseo_b: '강서남부 B조', ydp: '영등포', mapo: '마포' };
		var PF_LABEL = { baemin: '배민', coupang: '쿠팡' };
		var ST_BADGE = { active: 'success', suspended: 'danger', leave_request: 'warning', offboarded: 'dark' };

		function getCustom() {
			try { return JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}'); } catch (e) { return {}; }
		}
		function setCustom(obj) {
			localStorage.setItem(STORAGE_KEY, JSON.stringify(obj));
		}
		function getMerged() {
			return Object.assign({}, RIDER_SEED, getCustom());
		}

		function maskPhone(p) {
			var d = String(p).replace(/\D/g, '');
			if (d.length >= 11) return d.slice(0, 3) + '-****-' + d.slice(-4);
			if (d.length >= 10) return d.slice(0, 3) + '-***-' + d.slice(-4);
			return '010-****-****';
		}
		function maskAccount(acct) {
			var s = String(acct).replace(/\s/g, '');
			if (s.length <= 4) return '****';
			return s.slice(0, 3) + '-****-****' + s.slice(-2);
		}
		function todayStr() {
			var d = new Date();
			return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
		}
		function nowDt() {
			var d = new Date();
			return todayStr() + ' ' + String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
		}

		var DOC_PREVIEW_MAX = 380 * 1024;

		function readRegistrationDoc(file, categoryLabel) {
			return new Promise(function (resolve) {
				if (!file || !file.size) {
					resolve(null);
					return;
				}
				var doc = {
					category: categoryLabel,
					filename: file.name,
					size: file.size,
					mime: file.type || 'application/octet-stream',
					saved_at: nowDt(),
					preview_data_url: null,
					note: ''
				};
				var isImage = /^image\//.test(file.type);
				if (isImage && file.size <= DOC_PREVIEW_MAX) {
					var r = new FileReader();
					r.onload = function () {
						doc.preview_data_url = r.result;
						resolve(doc);
					};
					r.onerror = function () {
						doc.note = '파일 읽기 실패';
						resolve(doc);
					};
					r.readAsDataURL(file);
				} else {
					if (!isImage) doc.note = 'PDF 등 — 파일명·용량만 저장(목업)';
					else doc.note = '용량 초과 — 미리보기 생략(메타만 저장)';
					resolve(doc);
				}
			});
		}

		function collectRegistrationDocuments() {
			var tasks = [];
			var idF = document.getElementById('reg_doc_id').files[0];
			var bankF = document.getElementById('reg_doc_bank').files[0];
			var licF = document.getElementById('reg_doc_license').files[0];
			if (idF) tasks.push(readRegistrationDoc(idF, '신분증 사본'));
			if (bankF) tasks.push(readRegistrationDoc(bankF, '통장 사본'));
			if (licF) tasks.push(readRegistrationDoc(licF, '운전면허·자격 서류'));
			var extra = document.getElementById('reg_doc_extra').files;
			for (var i = 0; i < extra.length; i++) {
				tasks.push(readRegistrationDoc(extra[i], '추가 서류'));
			}
			return Promise.all(tasks);
		}

		function defaultPlatforms(primary) {
			return [
				{ code: 'baemin', label: '배달의민족', connected: primary === 'baemin', linked_id: primary === 'baemin' ? 'BM-NEW' : '' },
				{ code: 'coupang', label: '쿠팡이츠', connected: primary === 'coupang', linked_id: primary === 'coupang' ? 'CP-NEW' : '' }
			];
		}

		function escAttr(s) {
			return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
		}

		function renderTable() {
			var merged = getMerged();
			var rows = Object.keys(merged).map(function (k) { return merged[k]; });
			rows.sort(function (a, b) { return String(a.name).localeCompare(String(b.name), 'ko'); });
			var tb = document.getElementById('rider_tbody');
			tb.innerHTML = '';
			rows.forEach(function (r) {
				var st = ST_BADGE[r.status] || 'primary';
				var pf = PF_LABEL[r.primary_platform] || r.primary_platform;
				var loginId = r.login_id || '—';
				var hayRaw = [r.id, loginId, r.name, r.phone_masked, r.team].join(' ');
				var searchHay = hayRaw.toLowerCase();
				var detailUrl = DETAIL_BASE + '?id=' + encodeURIComponent(r.id);
				var tr = document.createElement('tr');
				tr.className = 'rider-row';
				tr.setAttribute('data-team', r.team_code || '');
				tr.setAttribute('data-status', r.status || '');
				tr.setAttribute('data-platform', r.primary_platform || '');
				tr.setAttribute('data-search', searchHay);
				tr.innerHTML =
					'<td class="fw-bold text-gray-900">' + escAttr(r.id) + '</td>' +
					'<td class="font-monospace fs-7 text-gray-800">' + escAttr(loginId) + '</td>' +
					'<td class="text-gray-900">' + escAttr(r.name) + '</td>' +
					'<td class="text-gray-700">' + escAttr(r.phone_masked) + '</td>' +
					'<td class="text-gray-800">' + escAttr(r.team) + '</td>' +
					'<td><span class="badge badge-light-primary">' + escAttr(pf) + '</span></td>' +
					'<td class="text-gray-700">' + escAttr(r.vehicle_type) + '</td>' +
					'<td><span class="badge badge-light-' + st + '">' + escAttr(r.status_label) + '</span></td>' +
					'<td class="text-gray-700">' + escAttr(r.join_date) + '</td>' +
					'<td class="text-gray-700">' + escAttr(r.last_login_at) + '</td>' +
					'<td class="text-end"><a href="' + escAttr(detailUrl) + '" class="btn btn-sm btn-light-primary">상세</a></td>';
				tb.appendChild(tr);
			});
			document.getElementById('rider_count_badge').textContent = '총 ' + rows.length + '명 (목업 · 시드+등록)';
		}

		function applyFilter() {
			var q = (document.getElementById('rider_filter_q').value || '').trim().toLowerCase();
			var team = document.getElementById('rider_filter_team').value;
			var st = document.getElementById('rider_filter_status').value;
			var pf = document.getElementById('rider_filter_platform').value;
			document.querySelectorAll('#rider_tbody tr.rider-row').forEach(function (tr) {
				var ok = true;
				if (team && tr.getAttribute('data-team') !== team) ok = false;
				if (ok && st && tr.getAttribute('data-status') !== st) ok = false;
				if (ok && pf && tr.getAttribute('data-platform') !== pf) ok = false;
				if (ok && q) {
					var hay = tr.getAttribute('data-search') || '';
					if (hay.indexOf(q) === -1) ok = false;
				}
				tr.style.display = ok ? '' : 'none';
			});
		}

		document.getElementById('rider_filter_apply').addEventListener('click', applyFilter);

		document.getElementById('rider_register_form').addEventListener('submit', function (ev) {
			ev.preventDefault();
			var loginId = document.getElementById('reg_login_id').value.trim();
			var pw = document.getElementById('reg_password').value;
			var pw2 = document.getElementById('reg_password_confirm').value;
			if (pw !== pw2) {
				window.alert('비밀번호가 일치하지 않습니다.');
				return;
			}
			var merged = getMerged();
			var loginLower = loginId.toLowerCase();
			for (var k in merged) {
				if (String(merged[k].login_id || '').toLowerCase() === loginLower) {
					window.alert('이미 사용 중인 로그인 ID입니다.');
					return;
				}
			}
			var customIdRaw = document.getElementById('reg_rider_id_custom').value.trim();
			var newId;
			if (customIdRaw !== '') {
				if (!/^R-[A-Za-z0-9._-]+$/.test(customIdRaw)) {
					window.alert('시스템 라이더 ID는 R- 로 시작하는 영문·숫자 형식이어야 합니다.');
					return;
				}
				if (merged[customIdRaw]) {
					window.alert('이미 사용 중인 라이더 ID입니다.');
					return;
				}
				newId = customIdRaw;
			} else {
				newId = 'R-REG-' + Date.now();
			}
			var teamCode = document.getElementById('reg_team').value;
			var primary = document.getElementById('reg_platform').value;
			var phone = document.getElementById('reg_phone').value.trim();
			var birth = document.getElementById('reg_birth').value.trim();
			var addr = document.getElementById('reg_address').value.trim();
			var bankAcct = document.getElementById('reg_bank_account').value.trim();
			var submitBtn = ev.submitter || document.querySelector('#rider_register_form button[type="submit"]');
			if (submitBtn) submitBtn.disabled = true;

			collectRegistrationDocuments().then(function (docList) {
				var activity = [{ at: nowDt(), type: '가입', detail: '관리자 화면에서 등록(목업)' }];
				if (docList.length) {
					activity.unshift({ at: nowDt(), type: '서류', detail: '제출 서류 ' + docList.length + '건(목업·브라우저 저장)' });
				}
				var row = {
					id: newId,
					login_id: loginId,
					password_plain: pw,
					mock_registered: true,
					name: document.getElementById('reg_name').value.trim(),
					phone_masked: maskPhone(phone),
					email: document.getElementById('reg_email').value.trim(),
					birth_masked: birth ? (birth.slice(0, 4) + '-**-**') : '—',
					team: TEAM_LABEL[teamCode] || teamCode,
					team_code: teamCode,
					primary_platform: primary,
					platforms: defaultPlatforms(primary),
					status: 'active',
					status_label: '활동 중',
					join_date: todayStr(),
					vehicle_type: document.getElementById('reg_vehicle').value,
					vehicle_number_masked: '—',
					address_short: addr || '—',
					bank_name: document.getElementById('reg_bank_name').value.trim(),
					bank_account_masked: maskAccount(bankAcct),
					account_holder: document.getElementById('reg_account_holder').value.trim(),
					kyc_status: 'pending',
					kyc_label: '추가 서류 대기',
					id_verified_at: '—',
					contract_signed_at: '',
					insurance_name: '가입 예정(목업)',
					insurance_expires: '',
					last_login_at: '—',
					last_delivery_at: '—',
					orders_mtd: 0,
					orders_last_week: 0,
					rating_avg: '—',
					penalty_points: 0,
					withdrawal_hold: false,
					admin_memo: '',
					documents: docList,
					recent_activity: activity
				};
				var c = getCustom();
				c[newId] = row;
				setCustom(c);
				renderTable();
				applyFilter();
				var modalEl = document.getElementById('kt_rider_register_modal');
				var mi = bootstrap.Modal.getInstance(modalEl) || bootstrap.Modal.getOrCreateInstance(modalEl);
				mi.hide();
				document.getElementById('rider_register_form').reset();
				window.alert('등록되었습니다. 상세에서 로그인·제출 서류를 확인할 수 있습니다.');
			}).catch(function () {
				window.alert('서류 파일을 읽는 중 오류가 났습니다.');
			}).finally(function () {
				if (submitBtn) submitBtn.disabled = false;
			});
		});

		document.getElementById('btn_rider_register_open').addEventListener('click', function () {
			document.getElementById('rider_register_form').reset();
		});

		renderTable();
	})();
	</script>

<?php require_once INC_PATH . '/app_content_close.php'; ?>
