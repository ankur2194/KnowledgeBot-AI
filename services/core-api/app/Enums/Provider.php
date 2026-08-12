<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The five vendors of ADR-001. A sixth is an ADR, not an enum case.
 *
 * THE STRING VALUES ARE NOT OURS TO CHOOSE. They are the first component of the capability-matrix
 * key in services/ai-service/app/providers/capabilities.py (`PROVIDERS`), and that matrix is what
 * decides whether a connection may embed at all. A value that does not match a key there resolves
 * to `_UNKNOWN` and is rejected as VENDOR_HAS_NO_ENDPOINT — which fails closed, but for the wrong
 * reason and with a message that blames the vendor rather than the typo.
 *
 * `nvidia_nim` is therefore spelled out in full, NOT `nim`. database/factories/
 * ProviderConnectionFactory's docblock lists `nim`; that predates capabilities.py and is a
 * contradiction reported rather than silently reconciled — the matrix key wins because it is the
 * only one of the two that anything executes against.
 */
enum Provider: string
{
    case OpenAI = 'openai';
    case Anthropic = 'anthropic';
    case DeepSeek = 'deepseek';
    case NvidiaNim = 'nvidia_nim';
    case OpenRouter = 'openrouter';

    /**
     * Every value, for the CHECK constraint and the FormRequest rule.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
