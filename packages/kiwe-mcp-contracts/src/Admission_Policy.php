<?php
declare(strict_types=1);

namespace Kiwe\MCP\Contracts;

/**
 * Admission decisions for already authenticated server context. Not authentication,
 * an endpoint, a generic executor, native object authorization or an approval store.
 */
final class Admission_Policy {
    private array $operations;
    private array $scopes;

    public function __construct() {
        $catalogue = json_decode((string) file_get_contents(dirname(__DIR__) . '/operation-catalogue.json'), true, 32, JSON_THROW_ON_ERROR);
        if (($catalogue['schema'] ?? '') !== 'kiwe.mcp-operation-catalogue.v1' || ($catalogue['runtimeExposure'] ?? '') !== 'none') {
            throw new \LogicException('Unrecognized MCP foundation catalogue.');
        }
        $this->operations = $catalogue['operations'];
        $this->scopes = array_values(array_unique(array_column($this->operations, 'scope')));
    }

    /** All callbacks and context arguments are server-owned, never JSON request flags. */
    public function decide(
        string $id, array $grant, array $site, int $now,
        callable $native_permission, callable $handler_ready,
        ?array $intent = null, ?callable $approval_lookup = null
    ): array {
        $deny = static fn(string $reason): array => ['admitted'=>false, 'reason'=>$reason, 'executed'=>false];
        $operation = $this->operations[$id] ?? null;
        if (!$operation) return $deny('unknown_operation');
        if (($site['mcpEnabled'] ?? null) !== true || ($site['quarantined'] ?? null) !== false) return $deny('app_unavailable');
        if (($grant['authenticated'] ?? null) !== true || ($grant['active'] ?? null) !== true) return $deny('authentication_required');
        if (!is_int($site['actorId'] ?? null) || $site['actorId'] < 1 || ($grant['actorId'] ?? null) !== $site['actorId']) return $deny('actor_mismatch');
        foreach (['installation','issuer','resource'] as $key) {
            if (!is_string($site[$key] ?? null) || $site[$key] === '' || !is_string($grant[$key] ?? null) || !hash_equals($site[$key], $grant[$key])) return $deny('site_mismatch');
        }
        if (!is_string($grant['clientId'] ?? null) || $grant['clientId'] === '' || !is_string($grant['id'] ?? null) || strlen($grant['id']) < 12) return $deny('invalid_grant');
        if ($now < 1 || !is_int($grant['issuedAt'] ?? null) || !is_int($grant['expiresAt'] ?? null)
            || $grant['issuedAt'] > $now || $grant['expiresAt'] <= $now
            || $grant['expiresAt'] <= $grant['issuedAt'] || $grant['expiresAt'] - $grant['issuedAt'] > 3600) return $deny('grant_expired');
        $scopes = $grant['scopes'] ?? null;
        if (!is_array($scopes) || !array_is_list($scopes) || count($scopes) > 32
            || array_filter($scopes, fn($s): bool => !is_string($s) || !in_array($s, $this->scopes, true))
            || !in_array($operation['scope'], $scopes, true)) return $deny('scope_denied');
        foreach ($operation['apps'] as $app) if (($site['apps'][$app] ?? null) !== true) return $deny('dependency_unavailable');
        foreach ($operation['dependencies'] as $dependency) if (($site['dependencies'][$dependency] ?? null) !== true) return $deny('dependency_unavailable');

        try {
            // Literal true only. Errors/truthy values cannot fall back to a cookie login.
            if ($native_permission($id, $operation, $intent) !== true) return $deny('native_permission_denied');
            if ($handler_ready($id, $operation) !== true) return $deny('operation_unavailable');
            if ($operation['effect'] === 'write') {
                if (!$intent || ($intent['operation'] ?? null) !== $id || !is_int($intent['expiresAt'] ?? null)
                    || $intent['expiresAt'] <= $now || $intent['expiresAt'] > $now + 900
                    || !is_string($intent['digest'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $intent['digest']) !== 1) return $deny('review_required');
                foreach (['actorId','installation','resource','clientId'] as $key) if (($intent[$key] ?? null) !== ($grant[$key] ?? null)) return $deny('intent_mismatch');
                if (($intent['grantId'] ?? null) !== $grant['id']) return $deny('intent_mismatch');
                if ($operation['approval'] === 'required'
                    && (!$approval_lookup || $approval_lookup($id, $grant, $intent) !== true)) return $deny('approval_required');
            }
        } catch (\Throwable) {
            return $deny('admission_unavailable');
        }
        return ['admitted'=>true, 'reason'=>'native_execution_required', 'executed'=>false, 'nativeRecheckRequired'=>true];
    }
}
