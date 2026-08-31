<?php

declare(strict_types=1);

/**
 * 조직 계층(어드민 > 총판 > 대리점) 조회 및 데이터 스코프 계산.
 *
 * 멀티테넌시의 단일 출처(§2.5). organizations 테이블은 작다는 전제로
 * 요청당 한 번 전체를 로드해 트리를 메모리에서 계산한다.
 */
final class Org
{
    public const LEVEL_ADMIN       = 'admin';
    public const LEVEL_DISTRIBUTOR = 'distributor';
    public const LEVEL_AGENCY      = 'agency';

    /** @var array<int, array<string,mixed>>|null  id => row */
    private static ?array $cache = null;

    public static function tableReady(): bool
    {
        return db_table_exists('organizations');
    }

    public static function levelLabel(string $level): string
    {
        return match ($level) {
            self::LEVEL_ADMIN       => '본사',
            self::LEVEL_DISTRIBUTOR => '총판',
            self::LEVEL_AGENCY      => '대리점',
            default                 => $level,
        };
    }

    /** @return array<int, array<string,mixed>> */
    private static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        self::$cache = [];
        if (!self::tableReady()) {
            return self::$cache;
        }

        foreach (db_rows('SELECT id, parent_id, level, code, name, is_active FROM organizations') as $r) {
            self::$cache[(int) $r['id']] = $r;
        }

        return self::$cache;
    }

    public static function clearCache(): void
    {
        self::$cache = null;
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return self::all()[$id] ?? null;
    }

    /** 현재 로그인 계정의 조직 행 (없으면 null) */
    public static function current(): ?array
    {
        $orgId = admin_org_id();

        return $orgId > 0 ? self::find($orgId) : null;
    }

    /**
     * 주어진 조직 + 모든 하위 조직 id (자신 포함).
     *
     * @return list<int>
     */
    public static function subtreeOrgIds(int $orgId): array
    {
        $all = self::all();

        $childrenOf = [];
        foreach ($all as $id => $r) {
            $pid = $r['parent_id'] !== null ? (int) $r['parent_id'] : 0;
            $childrenOf[$pid][] = (int) $id;
        }

        $out   = [];
        $stack = [$orgId];
        while ($stack !== []) {
            $cur = array_pop($stack);
            if (!isset($all[$cur]) || in_array($cur, $out, true)) {
                continue;
            }
            $out[] = $cur;
            foreach ($childrenOf[$cur] ?? [] as $child) {
                $stack[] = $child;
            }
        }

        return $out;
    }

    /**
     * 주어진 조직 하위의 대리점(agency) id (자신이 agency면 자신만).
     *
     * @return list<int>
     */
    public static function subtreeAgencyIds(int $orgId): array
    {
        $all = self::all();
        $ids = [];
        foreach (self::subtreeOrgIds($orgId) as $id) {
            if (($all[$id]['level'] ?? '') === self::LEVEL_AGENCY) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * 현재 계정이 볼 수 있는 대리점 id 집합.
     *  - admin(루트): null  → 전체(필터 없음)
     *  - distributor: 하위 모든 agency
     *  - agency: 자기 자신
     *  - 조직 미지정: super면 null(전체), 그 외 [] (차단)
     *
     * @return list<int>|null
     */
    public static function scopeAgencyIds(): ?array
    {
        $org = self::current();
        if ($org === null) {
            return admin_has_role('super') ? null : [];
        }

        $level = (string) $org['level'];
        if ($level === self::LEVEL_ADMIN) {
            return null;
        }
        if ($level === self::LEVEL_DISTRIBUTOR) {
            return self::subtreeAgencyIds((int) $org['id']);
        }

        return [(int) $org['id']];
    }

    /**
     * 현재 계정 스코프 조직 id 집합 (공지·배너 broadcast 포함 범위).
     *  - admin: null(전체) · 그 외: 자기 + 하위 모든 조직
     *
     * @return list<int>|null
     */
    public static function scopeOrgIds(): ?array
    {
        $org = self::current();
        if ($org === null) {
            return admin_has_role('super') ? null : [];
        }
        if ((string) $org['level'] === self::LEVEL_ADMIN) {
            return null;
        }

        return self::subtreeOrgIds((int) $org['id']);
    }

    /**
     * 목록 쿼리용 스코프 WHERE 조각.
     * 반환 [sql, params]:
     *   - 전체 허용: ['', []]
     *   - 제한:      ['agency_id IN (?,?)', [3,4]]
     *   - 차단:      ['1=0', []]
     *
     * @return array{0:string,1:list<int>}
     */
    public static function agencyScopeClause(string $column): array
    {
        $ids = self::scopeAgencyIds();
        if ($ids === null) {
            return ['', []];
        }
        if ($ids === []) {
            return ['1=0', []];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));

        return ["{$column} IN ({$ph})", array_values($ids)];
    }

    /**
     * 목록 쿼리용 조직 스코프 WHERE 조각 (공지·배너 등 org_id 기준).
     * 반환은 agencyScopeClause 와 동일 규약 (''=전체, '1=0'=차단).
     *
     * @return array{0:string,1:list<int>}
     */
    public static function orgScopeClause(string $column): array
    {
        $ids = self::scopeOrgIds();
        if ($ids === null) {
            return ['', []];
        }
        if ($ids === []) {
            return ['1=0', []];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));

        return ["{$column} IN ({$ph})", array_values($ids)];
    }

    /**
     * 주어진 조직 + 모든 상위(조상) 조직 id (자신 포함, 루트까지).
     * 라이더 공지·배너 broadcast 가시성 계산용 (대리점 + 상위 총판·본사).
     *
     * @return list<int>
     */
    public static function ancestorOrgIds(int $orgId): array
    {
        $all = self::all();
        $out = [];
        $cur = $orgId;
        while ($cur > 0 && isset($all[$cur]) && !in_array($cur, $out, true)) {
            $out[] = $cur;
            $cur   = $all[$cur]['parent_id'] !== null ? (int) $all[$cur]['parent_id'] : 0;
        }

        return $out;
    }

    /**
     * 대리점 기준 조직 체인 — 수수료를 나눠 갖는 조직들의 id.
     *
     * 대리점 위에 총판이 없는(본사 직속) 구조도 있으므로, 없는 레벨은 0으로 돌려준다.
     * 수수료 배분(리스·영업대행)에서 "그 몫을 받을 조직이 실제로 있는지" 판단하는 데 쓴다.
     *
     * @return array{agency:int, distributor:int, hq:int}
     */
    /**
     * 본사(admin 레벨) 조직 id. 트리 루트라 항상 1개다.
     * 단일 가맹점·단일 실계좌 구조(2026-08-15 갑 확정)에서 "돈이 실제로 드나드는 주체"를
     * 가리킬 때 쓴다 — 라이더 이체·대리점 인출의 **출금 원천 계좌**가 본사 것이다.
     */
    public static function hqId(): int
    {
        $row = db_row("SELECT id FROM organizations WHERE level = 'admin' ORDER BY id ASC LIMIT 1");

        return $row !== null ? (int) $row['id'] : 0;
    }

    /**
     * 대행수수료 부담 주체 — 대리점별 설정(2026-09-01 갑). 'rider'(기본) | 'agency'.
     * 컬럼이 아직 없거나 값이 이상하면 안전하게 'rider'(기존 동작).
     */
    public static function agencyFeePayer(int $orgId): string
    {
        if ($orgId < 1 || !db_table_exists('organizations')) {
            return 'rider';
        }
        static $hasCol = null;
        if ($hasCol === null) {
            $hasCol = in_array('agency_fee_payer', array_column(db_rows('SHOW COLUMNS FROM organizations'), 'Field'), true);
        }
        if (!$hasCol) {
            return 'rider';
        }
        $v = (string) (db_row('SELECT agency_fee_payer FROM organizations WHERE id = ? LIMIT 1', [$orgId])['agency_fee_payer'] ?? 'rider');

        return $v === 'agency' ? 'agency' : 'rider';
    }

    public static function chainForAgency(int $agencyId): array
    {
        $out = ['agency' => 0, 'distributor' => 0, 'hq' => 0];
        if ($agencyId < 1) {
            return $out;
        }
        foreach (self::ancestorOrgIds($agencyId) as $oid) {
            $org = self::find($oid);
            if ($org === null) {
                continue;
            }
            $lvl = (string) $org['level'];
            if ($lvl === self::LEVEL_AGENCY && $out['agency'] === 0) {
                $out['agency'] = $oid;
            } elseif ($lvl === self::LEVEL_DISTRIBUTOR && $out['distributor'] === 0) {
                $out['distributor'] = $oid;
            } elseif ($lvl === self::LEVEL_ADMIN && $out['hq'] === 0) {
                $out['hq'] = $oid;
            }
        }

        return $out;
    }

    /** 현재 계정이 해당 대리점 데이터에 접근(읽기/쓰기) 가능한지 — 스코프 내 여부 */
    public static function canAccessAgency(int $agencyId): bool
    {
        $ids = self::scopeAgencyIds();
        if ($ids === null) {
            return true;
        }

        return in_array($agencyId, $ids, true);
    }

    /** 현재 계정이 해당 조직(공지/배너 등)에 접근 가능한지 — 스코프 내 여부 */
    public static function canAccessOrg(int $orgId): bool
    {
        $ids = self::scopeOrgIds();
        if ($ids === null) {
            return true;
        }

        return in_array($orgId, $ids, true);
    }
}
