<?php

declare(strict_types=1);

namespace Kode\AiAgent\Domain\ValueObject;

/**
 * API 凭证值对象
 * 
 * 封装 API 认证信息，支持多种认证方式：
 * - 单 API Key
 * - 双 Key（主备）
 * - 多 Key 轮换
 * - AppKey + AppSecret（阿里云、百度等）
 * - 自定义认证头
 * 
 * @package Kode\AiAgent\Domain\ValueObject
 * 
 * @example
 * ```php
 * // 单 Key
 * $key = ApiKey::fromEnv('OPENAI_API_KEY');
 * 
 * // 双 Key（主备）
 * $key = ApiKey::dual('sk-primary', 'sk-secondary');
 * 
 * // 轮换模式
 * $key = ApiKey::rotating(['sk-1', 'sk-2', 'sk-3'], 'round_robin');
 * 
 * // AppKey + AppSecret（阿里云、百度等）
 * $key = ApiKey::appSecret('app-key-xxx', 'app-secret-xxx');
 * 
 * // 从配置创建
 * $key = ApiKey::fromArray([
 *     'app_key' => 'xxx',
 *     'app_secret' => 'xxx',
 * ]);
 * ```
 */
readonly class ApiKey
{
    private function __construct(
        private array $keys,
        private string $strategy = 'single',
        private int $currentIndex = 0,
        private ?string $appKey = null,
        private ?string $appSecret = null,
        private array $extra = [],
    ) {
        if (empty($keys) && $appKey === null) {
            throw new \InvalidArgumentException('API Key 列表不能为空');
        }
    }

    /**
     * 从环境变量创建
     */
    public static function fromEnv(string $envKey): self
    {
        $value = getenv($envKey);
        
        if ($value === false || $value === '') {
            throw new \InvalidArgumentException("环境变量 {$envKey} 未设置");
        }
        
        return new self([$value], 'single');
    }

    /**
     * 从字符串创建
     */
    public static function fromString(string $value): self
    {
        if (strlen($value) < 16) {
            throw new \InvalidArgumentException('API Key 格式无效');
        }
        
        return new self([$value], 'single');
    }

    /**
     * 创建双 Key（主备模式）
     *
     * @param string $primary 主 Key
     * @param string $secondary 备 Key
     */
    public static function dual(string $primary, string $secondary): self
    {
        return new self([$primary, $secondary], 'failover');
    }

    /**
     * 创建轮换 Key
     *
     * @param array $keys Key 列表
     * @param string $strategy 轮换策略：round_robin, random, failover
     */
    public static function rotating(array $keys, string $strategy = 'round_robin'): self
    {
        foreach ($keys as $key) {
            if (strlen($key) < 16) {
                throw new \InvalidArgumentException('API Key 格式无效');
            }
        }
        
        return new self($keys, $strategy);
    }

    /**
     * 创建 AppKey + AppSecret 凭证
     * 
     * 适用于阿里云、百度、腾讯云等需要双凭证的平台
     *
     * @param string $appKey App Key
     * @param string $appSecret App Secret
     * @param array $extra 额外参数（如 region、account_id 等）
     */
    public static function appSecret(string $appKey, string $appSecret, array $extra = []): self
    {
        if (strlen($appKey) < 8) {
            throw new \InvalidArgumentException('AppKey 格式无效');
        }
        
        if (strlen($appSecret) < 16) {
            throw new \InvalidArgumentException('AppSecret 格式无效');
        }
        
        return new self([], 'app_secret', 0, $appKey, $appSecret, $extra);
    }

    /**
     * 从配置数组创建
     *
     * @param array $config 配置
     *   - api_key: 单 Key
     *   - keys: Key 列表（轮换模式）
     *   - primary/secondary: 主备 Key
     *   - strategy: 轮换策略
     *   - app_key/app_secret: AppKey + AppSecret 模式
     *   - extra: 额外参数
     */
    public static function fromArray(array $config): self
    {
        // AppKey + AppSecret 模式
        if (isset($config['app_key']) && isset($config['app_secret'])) {
            return self::appSecret(
                $config['app_key'],
                $config['app_secret'],
                $config['extra'] ?? []
            );
        }
        
        // 多 Key 轮换模式
        if (isset($config['keys']) && is_array($config['keys'])) {
            return self::rotating($config['keys'], $config['strategy'] ?? 'round_robin');
        }
        
        // 双 Key 主备模式
        if (isset($config['primary']) && isset($config['secondary'])) {
            return self::dual($config['primary'], $config['secondary']);
        }
        
        // 单 Key 模式
        if (isset($config['api_key'])) {
            return self::fromString($config['api_key']);
        }
        
        throw new \InvalidArgumentException('无效的 API Key 配置');
    }

    /**
     * 获取当前 Key
     */
    public function current(): string
    {
        // AppKey + AppSecret 模式
        if ($this->appKey !== null) {
            return $this->appKey;
        }
        
        return match ($this->strategy) {
            'round_robin' => $this->keys[$this->currentIndex % count($this->keys)],
            'random' => $this->keys[array_rand($this->keys)],
            'failover' => $this->keys[$this->currentIndex % count($this->keys)],
            default => $this->keys[0],
        };
    }

    /**
     * 获取 AppKey
     */
    public function appKey(): ?string
    {
        return $this->appKey;
    }

    /**
     * 获取 AppSecret
     */
    public function secret(): ?string
    {
        return $this->appSecret;
    }

    /**
     * 获取额外参数
     */
    public function extra(string $key = null): mixed
    {
        if ($key === null) {
            return $this->extra;
        }
        
        return $this->extra[$key] ?? null;
    }

    /**
     * 检查是否为 AppSecret 模式
     */
    public function isAppSecretMode(): bool
    {
        return $this->appKey !== null && $this->appSecret !== null;
    }

    /**
     * 获取下一个 Key（轮换模式）
     */
    public function next(): string
    {
        if ($this->strategy === 'round_robin' && !empty($this->keys)) {
            $nextIndex = ($this->currentIndex + 1) % count($this->keys);
            return $this->keys[$nextIndex];
        }
        
        return $this->current();
    }

    /**
     * 切换到下一个 Key
     */
    public function rotate(): self
    {
        if ($this->strategy !== 'round_robin' || count($this->keys) <= 1) {
            return $this;
        }
        
        return new self(
            $this->keys,
            $this->strategy,
            ($this->currentIndex + 1) % count($this->keys),
            $this->appKey,
            $this->appSecret,
            $this->extra,
        );
    }

    /**
     * 故障转移
     */
    public function failover(): self
    {
        if (count($this->keys) <= 1) {
            return $this;
        }
        
        $nextIndex = ($this->currentIndex + 1) % count($this->keys);
        
        return new self(
            $this->keys,
            $this->strategy,
            $nextIndex,
            $this->appKey,
            $this->appSecret,
            $this->extra,
        );
    }

    /**
     * 获取原始值（兼容单 Key 模式）
     */
    public function value(): string
    {
        return $this->current();
    }

    /**
     * 获取脱敏显示值
     */
    public function masked(): string
    {
        if ($this->appKey !== null) {
            return $this->maskValue($this->appKey);
        }
        
        return $this->maskValue($this->current());
    }

    /**
     * 获取所有 Key 的脱敏显示
     */
    public function maskedAll(): array
    {
        return array_map(
            fn($key) => $this->maskValue($key),
            $this->keys
        );
    }

    /**
     * 获取脱敏后的凭证信息
     */
    public function maskedCredentials(): array
    {
        $result = [];
        
        if ($this->appKey !== null) {
            $result['app_key'] = $this->maskValue($this->appKey);
        }
        
        if ($this->appSecret !== null) {
            $result['app_secret'] = $this->maskValue($this->appSecret, 8);
        }
        
        if (!empty($this->keys)) {
            $result['keys'] = $this->maskedAll();
        }
        
        return $result;
    }

    /**
     * 验证 Key 格式
     */
    public function isValid(): bool
    {
        if ($this->appKey !== null) {
            return strlen($this->appKey) >= 8 
                && strlen($this->appSecret ?? '') >= 16;
        }
        
        foreach ($this->keys as $key) {
            if (strlen($key) < 16) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * 检查是否为空
     */
    public function isEmpty(): bool
    {
        return empty($this->keys) && $this->appKey === null;
    }

    /**
     * 获取 Key 数量
     */
    public function count(): int
    {
        if ($this->appKey !== null) {
            return 1;
        }
        
        return count($this->keys);
    }

    /**
     * 获取轮换策略
     */
    public function strategy(): string
    {
        return $this->strategy;
    }

    /**
     * 是否为轮换模式
     */
    public function isRotating(): bool
    {
        return count($this->keys) > 1;
    }

    /**
     * 生成签名（用于需要签名的 API）
     *
     * @param string $method HTTP 方法
     * @param string $path 请求路径
     * @param array $params 请求参数
     * @param string $algorithm 签名算法
     */
    public function sign(string $method, string $path, array $params = [], string $algorithm = 'sha256'): string
    {
        if ($this->appSecret === null) {
            throw new \RuntimeException('签名需要 AppSecret');
        }
        
        // 构建签名字符串
        ksort($params);
        $queryString = http_build_query($params);
        $stringToSign = strtoupper($method) . "\n" . $path . "\n" . $queryString;
        
        return hash_hmac($algorithm, $stringToSign, $this->appSecret);
    }

    /**
     * 生成带签名的请求头
     *
     * @param string $method HTTP 方法
     * @param string $path 请求路径
     * @param array $params 请求参数
     */
    public function signedHeaders(string $method, string $path, array $params = []): array
    {
        if (!$this->isAppSecretMode()) {
            return [];
        }
        
        $timestamp = time();
        $nonce = bin2hex(random_bytes(16));
        $signature = $this->sign($method, $path, $params);
        
        return [
            'X-App-Key' => $this->appKey,
            'X-Timestamp' => (string) $timestamp,
            'X-Nonce' => $nonce,
            'X-Signature' => $signature,
        ];
    }

    private function maskValue(string $value, int $showChars = 4): string
    {
        $length = strlen($value);
        
        if ($length <= $showChars * 2) {
            return '****';
        }
        
        return substr($value, 0, $showChars) 
            . '...' 
            . substr($value, -$showChars);
    }

    public function __toString(): string
    {
        return $this->masked();
    }
}
