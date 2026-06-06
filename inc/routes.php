<?php

declare(strict_types=1);

return [
    'dashboard' => ['title' => '대시보드', 'view' => 'dashboard'],
    'settlement/upload' => ['title' => '엑셀 업로드', 'view' => 'settlement_upload'],
    'settlement/upload-detail' => ['title' => '업로드 상세', 'view' => 'settlement_upload_detail'],
    'settlement/history' => ['title' => '업로드 이력', 'view' => 'settlement_history'],
    'settlement/fees' => ['title' => '정산 수수료 내역', 'view' => 'settlement_fees'],
    'settlement/fee-detail' => ['title' => '정산 수수료 상세', 'view' => 'settlement_fee_detail'],
    'settlement/parse-errors' => ['title' => '파싱 오류 상세', 'view' => 'settlement_parse_errors'],
    'promotion/rules' => ['title' => '프로모션 규칙', 'view' => 'promotion_rules'],
    'promotion/batch' => ['title' => '배치 실행', 'view' => 'promotion_batch'],
    'promotion/history' => ['title' => '실행 이력', 'view' => 'promotion_history'],
    'deduction/entries' => ['title' => '차감 내역 등록', 'view' => 'deduction_entries'],
    'deduction/agency-fee' => ['title' => '선공제(대행 수수료) 설정', 'view' => 'deduction_agency_fee'],
    'deduction/auto' => ['title' => '자동 차감 설정', 'view' => 'deduction_auto'],
    'deduction/installment' => ['title' => '할부 관리', 'view' => 'deduction_installment'],
    'stats/summary' => ['title' => '종합 통계', 'view' => 'stats_summary'],
    'stats/export' => ['title' => '기초데이터 엑셀 내보내기', 'view' => 'stats_export'],
    'withdrawal/list' => ['title' => '출금 신청 목록', 'view' => 'withdrawal_list'],
    'withdrawal/settings' => ['title' => '출금 정책 설정', 'view' => 'withdrawal_settings'],
    'withdrawal/download' => ['title' => '출금 다운로드', 'view' => 'withdrawal_download'],
    'withdrawal/complete' => ['title' => '출금 처리 완료', 'view' => 'withdrawal_complete'],
    'content/notices' => ['title' => '공지 관리', 'view' => 'content_notices'],
    'content/banners' => ['title' => '광고 배너', 'view' => 'content_banners'],
    'riders/list' => ['title' => '라이더 관리', 'view' => 'riders_list'],
    'riders/detail' => ['title' => '라이더 상세', 'view' => 'riders_detail'],
    'system/admins' => ['title' => '관리자 계정·권한', 'view' => 'system_admins'],
    'system/codes' => ['title' => '코드/마스터', 'view' => 'system_codes'],
    'system/settlement-excel' => ['title' => '정산 엑셀 열기 암호', 'view' => 'system_settlement_excel'],
    'system/audit' => ['title' => '감사 로그', 'view' => 'system_audit'],
];
