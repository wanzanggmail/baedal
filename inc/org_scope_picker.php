<?php

declare(strict_types=1);

require_once __DIR__ . '/Organization.php';
require_once __DIR__ . '/Org.php';

/**
 * 「총판 → 대리점」 연동 선택기 — 관리자 화면 공용 컴포넌트.
 *
 * 갑 지시(2026-08-31): **대리점을 한 번에 고르지 말고, 총판을 먼저 고른 뒤 그 소속
 * 대리점만 검색형(select2)으로 고른다.** 이 규칙을 여러 화면에 흩뿌리면 언젠가
 * 한쪽만 어긋나므로, 마크업·동작을 여기 한 곳에 모은다.
 *
 * 원형은 `debt_list.php` 에 있던 검증된 패턴이다. 그대로 함수로 뽑았다.
 *
 * 동작:
 *   - 대리점 계정으로 로그인하면(스코프가 자기 대리점뿐) **아무것도 렌더하지 않는다** —
 *     고를 것이 없다. 호출부는 `org_scope_picker_visible()` 로 미리 확인할 수 있다.
 *   - 총판을 안 고르면 대리점 셀렉트는 비활성. 총판을 고르면 그 소속만 남는다.
 *   - 대리점 셀렉트는 select2 검색. JS 는 `assets/js/org-scope-picker.js`(shell_close 로드).
 *
 * 서버측 필터링: 폼이 제출하는 값은 `{prefix}_distributor`, `{prefix}_agency`.
 *   - `agency` 가 있으면 그 대리점, 없고 `distributor` 만 있으면
 *     `Org::subtreeAgencyIds($dist)` 로 하위 대리점 전체.
 *   `org_scope_picker_agency_filter()` 가 이 WHERE 절을 만들어 준다.
 */

/**
 * 이 계정에서 선택기를 보여줄 필요가 있는가.
 * 대리점 계정(스코프가 자기 하나)이면 고를 게 없으니 false.
 */
function org_scope_picker_visible(): bool
{
    if (function_exists('admin_org_level') && admin_org_level() === Org::LEVEL_AGENCY) {
        return false;
    }

    return Organization::agencyOptions() !== [];
}

/**
 * 선택기 마크업을 출력한다.
 *
 * @param string $prefix   폼 필드·엘리먼트 id 접두어(화면마다 유일해야 함). 예: 'up', 'od'
 * @param int    $selDist  선택된 총판 id (없으면 0)
 * @param int    $selAgency 선택된 대리점 id (없으면 0)
 * @param array{
 *     dist_label?:string, agency_label?:string,
 *     dist_col?:string, agency_col?:string,
 *     dist_name?:string, agency_name?:string,
 *     required?:bool, agency_all?:bool,
 *     submit_on_change?:bool,
 *     extra_options?:list<array{value:int|string, label:string, selected?:bool}>
 * } $opt
 *     dist_label/agency_label : 라벨 문구(기본 '총판'/'대리점')
 *     dist_col/agency_col     : 부트스트랩 컬럼 클래스(기본 'col-md-3')
 *     dist_name/agency_name   : submit 될 필드명(기본 '{prefix}_distributor'/'{prefix}_agency')
 *     required                : 대리점을 반드시 골라야 하는 화면인가(업로드 등). 기본 false
 *     agency_all              : 대리점 미선택 시 '전체'를 허용하는가(조회 화면). 기본 true
 *     submit_on_change        : 대리점을 고르면 폼을 자동 제출하는가(대상 선택기). 기본 false
 *     extra_options           : 대리점이 아닌 특수 옵션(본사·전역기본값 등). 총판을 안 고른
 *                               상태에서만 보인다(data-parent 없음). 기본 없음
 */
function org_scope_picker(string $prefix, int $selDist = 0, int $selAgency = 0, array $opt = []): void
{
    if (!org_scope_picker_visible()) {
        return;
    }

    $dists    = Organization::distributorOptions();
    $agencies = Organization::agencyOptions();

    // 선택된 대리점의 소속 총판을 알아내, 총판이 안 넘어와도 자동으로 맞춰준다.
    if ($selAgency > 0 && $selDist === 0) {
        foreach ($agencies as $a) {
            if ((int) $a['id'] === $selAgency) {
                $selDist = (int) ($a['parent_id'] ?? 0);
                break;
            }
        }
    }

    $distLabel   = (string) ($opt['dist_label'] ?? '총판');
    $agencyLabel = (string) ($opt['agency_label'] ?? '대리점');
    $distCol     = (string) ($opt['dist_col'] ?? 'col-md-3');
    $agencyCol   = (string) ($opt['agency_col'] ?? 'col-md-3');
    $distName    = (string) ($opt['dist_name'] ?? ($prefix . '_distributor'));
    $agencyName  = (string) ($opt['agency_name'] ?? ($prefix . '_agency'));
    $required    = (bool) ($opt['required'] ?? false);
    $agencyAll   = (bool) ($opt['agency_all'] ?? true);
    $submitCh    = (bool) ($opt['submit_on_change'] ?? false);
    $extra       = (array) ($opt['extra_options'] ?? []);
    $ddParent    = (string) ($opt['dropdown_parent'] ?? ''); // 모달 안이면 그 셀렉터(#id)

    $esc      = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    $distId   = $prefix . '_osp_dist';
    $agencyId = $prefix . '_osp_agency';
    ?>
	<div class="<?= $esc($distCol) ?> org-scope-picker" data-osp="<?= $esc($prefix) ?>">
		<label class="form-label fw-semibold"><?= $esc($distLabel) ?><?php if ($required) : ?> <span class="text-danger">*</span><?php endif; ?></label>
		<select class="form-select form-select-solid" name="<?= $esc($distName) ?>" id="<?= $esc($distId) ?>" data-control="select2" data-placeholder="<?= $esc($distLabel) ?> 선택"<?= $ddParent !== '' ? ' data-dropdown-parent="' . $esc($ddParent) . '"' : '' ?>>
			<option value=""></option>
			<?php foreach ($dists as $d) : ?>
			<option value="<?= (int) $d['id'] ?>"<?= $selDist === (int) $d['id'] ? ' selected' : '' ?>><?= $esc((string) $d['name']) ?></option>
			<?php endforeach; ?>
		</select>
	</div>
	<div class="<?= $esc($agencyCol) ?> org-scope-picker" data-osp="<?= $esc($prefix) ?>">
		<label class="form-label fw-semibold"><?= $esc($agencyLabel) ?><?php if ($required) : ?> <span class="text-danger">*</span><?php endif; ?></label>
		<?php // data-control="select2" 를 일부러 빼둔다 — 자동 스캐너가 먼저 초기화하면
		      // 커스텀 matcher 와 이중 초기화돼 충돌한다. org-scope-picker.js 가 직접 초기화한다. ?>
		<select class="form-select form-select-solid" name="<?= $esc($agencyName) ?>" id="<?= $esc($agencyId) ?>"
			data-osp-dist="<?= $esc($distId) ?>"
			data-osp-all="<?= $agencyAll ? '1' : '0' ?>"
			data-osp-required="<?= $required ? '1' : '0' ?>"
			data-osp-submit="<?= $submitCh ? '1' : '0' ?>"
			data-osp-hasextra="<?= $extra !== [] ? '1' : '0' ?>"
			<?= $ddParent !== '' ? 'data-osp-ddparent="' . $esc($ddParent) . '"' : '' ?>
			style="width:100%"<?= ($selDist > 0 || $extra !== []) ? '' : ' disabled' ?>>
			<option value=""></option>
			<?php // 특수 옵션(본사·전역 등) — 대리점이 아니므로 data-parent 없음. 총판 미선택 때만 보인다. ?>
			<?php foreach ($extra as $x) : ?>
			<option value="<?= $esc((string) $x['value']) ?>"<?= !empty($x['selected']) ? ' selected' : '' ?>><?= $esc((string) $x['label']) ?></option>
			<?php endforeach; ?>
			<?php foreach ($agencies as $a) : ?>
			<option value="<?= (int) $a['id'] ?>" data-parent="<?= (int) ($a['parent_id'] ?? 0) ?>"<?= $selAgency === (int) $a['id'] ? ' selected' : '' ?>><?= $esc((string) $a['name']) ?></option>
			<?php endforeach; ?>
		</select>
	</div>
	<?php
}

/**
 * 제출된 총판/대리점 선택으로 대리점 필터 WHERE 절을 만든다.
 *
 * @param string $column  대리점 id 컬럼(예: 'r.agency_id', 'su.agency_id')
 * @param array<string,mixed> $req  요청 배열($_GET 등)
 * @param string $prefix  org_scope_picker 와 같은 접두어
 * @return array{0:string, 1:list<int>}  [SQL 조각, 파라미터]  — 조건 없으면 ['', []]
 */
function org_scope_picker_agency_filter(string $column, array $req, string $prefix): array
{
    $agency = (int) ($req[$prefix . '_agency'] ?? 0);
    $dist   = (int) ($req[$prefix . '_distributor'] ?? 0);

    if ($agency > 0) {
        return [$column . ' = ?', [$agency]];
    }
    if ($dist > 0) {
        $ids = Org::subtreeAgencyIds($dist);
        if ($ids === []) {
            return ['1=0', []]; // 총판은 골랐는데 하위 대리점이 없음 → 빈 결과
        }

        return [$column . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', array_values($ids)];
    }

    return ['', []];
}
