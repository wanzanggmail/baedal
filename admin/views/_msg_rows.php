<?php
/** messages 초기 큐 표 본문 — $rows, $esc, $statusBadge 를 상위 뷰에서 받는다. */
declare(strict_types=1);
if (($rows ?? []) === []) : ?>
	<tr><td colspan="6" class="text-center text-muted py-6">큐가 비어 있습니다.</td></tr>
<?php else : foreach ($rows as $r) :
	$st = (string) $r['status'];
	$canAct = in_array($st, ['queued', 'failed'], true);
	$when = $st === 'sent' ? (string) ($r['sent_at'] ?? '') : (string) ($r['scheduled_at'] ?? $r['created_at'] ?? '');
	$content = mb_substr((string) $r['content'], 0, 60) . (mb_strlen((string) $r['content']) > 60 ? '…' : ''); ?>
	<tr>
		<td><span class="badge badge-light-<?= $r['channel'] === 'alimtalk' ? 'info' : 'primary' ?>"><?= $esc(MessageQueue::channelLabel((string) $r['channel'])) ?></span></td>
		<td><?= $r['recipient_name'] ? $esc((string) $r['recipient_name']) . '<br>' : '' ?><span class="text-muted"><?= $esc((string) $r['recipient_phone']) ?></span></td>
		<td>
			<?= $r['title'] ? '<div class="fw-semibold">' . $esc((string) $r['title']) . '</div>' : '' ?><?= $esc($content) ?>
			<?php if (!empty($r['error'])) : ?><div class="text-danger fs-9"><?= $esc((string) $r['error']) ?></div><?php endif; ?>
			<?php if (!empty($r['provider_ref'])) : ?><div class="text-muted fs-9"><?= $esc((string) $r['provider_ref']) ?></div><?php endif; ?>
		</td>
		<td><span class="badge badge-light-<?= $statusBadge[$st] ?? 'secondary' ?>"><?= $esc(MessageQueue::statusLabel($st)) ?></span></td>
		<td class="text-muted"><?= $esc($when) ?></td>
		<td class="text-end">
			<?php if ($canAct) : ?>
			<button class="btn btn-sm btn-light-primary py-1 px-2 me-1 m-send" data-id="<?= (int) $r['id'] ?>">발송</button>
			<button class="btn btn-sm btn-light py-1 px-2 m-cancel" data-id="<?= (int) $r['id'] ?>">취소</button>
			<?php endif; ?>
		</td>
	</tr>
<?php endforeach; endif; ?>
