<?php
/** tax_dashboard 초기 표 본문 — $agencies, $won, $esc 를 상위 뷰에서 받는다. */
declare(strict_types=1);
if (($agencies ?? []) === []) : ?>
	<tr><td colspan="5" class="text-center text-muted py-6">이 달에 걷힌 원천세가 없습니다.</td></tr>
<?php else : foreach ($agencies as $a) : ?>
	<tr>
		<td class="fw-semibold"><?= $esc($a['agency_name']) ?> <span class="text-muted fs-8"><?= $esc($a['code']) ?></span></td>
		<td class="text-end fw-semibold"><?= $won($a['accrued']) ?></td>
		<td class="text-end text-muted"><?= $won($a['collected']) ?></td>
		<td class="text-end fw-bold<?= (int) $a['uncollected'] > 0 ? ' text-primary' : '' ?>"><?= $won($a['uncollected']) ?></td>
		<td class="text-center">
			<?php if ((int) $a['uncollected'] > 0) : ?>
			<button type="button" class="btn btn-sm btn-light-primary tax-collect-one" data-agency="<?= (int) $a['agency_id'] ?>" data-name="<?= $esc($a['agency_name']) ?>">수집</button>
			<?php else : ?>
			<span class="badge badge-light-success fs-8">완료</span>
			<?php endif; ?>
		</td>
	</tr>
<?php endforeach; endif; ?>
