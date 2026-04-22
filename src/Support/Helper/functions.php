<?php

declare(strict_types=1);

/**
 * AI Agent 辅助函数
 * 
 * 注意：基础工具函数已由 kode/tools 包提供
 * 
 * @see \Kode\Message\Message 响应体
 * @see \Kode\String\Str 字符串处理
 * @see \Kode\Array\Arr 数组处理
 * @see \Kode\Time\Time 时间处理
 */

if (!function_exists('ai_token_count')) {
    /**
     * 估算 Token 数量
     *
     * 使用简单的估算方法：英文约 4 字符 = 1 token，中文约 2 字符 = 1 token
     *
     * @param string $text 文本内容
     * @return int 估算的 Token 数量
     *
     * @example
     * ```php
     * $tokens = ai_token_count('你好，世界');
     * ```
     */
    function ai_token_count(string $text): int
    {
        $chineseCount = preg_match_all('/[\x{4e00}-\x{9fff}]/u', $text);
        $totalLength = strlen($text);

        $chineseTokens = (int) ($chineseCount / 1.5);
        $englishTokens = (int) (($totalLength - $chineseCount * 3) / 4);

        return max(1, $chineseTokens + $englishTokens);
    }
}

if (!function_exists('ai_sanitize_log')) {
    /**
     * 日志数据脱敏
     *
     * 移除或遮蔽敏感信息，用于安全日志记录
     *
     * @param array $data 原始数据
     * @return array 脱敏后的数据
     *
     * @example
     * ```php
     * $safe = ai_sanitize_log([
     *     'api_key' => 'sk-xxx',
     *     'message' => '你好',
     * ]);
     * // ['api_key' => 'sk-***REDACTED***', 'message' => '你好']
     * ```
     */
    function ai_sanitize_log(array $data): array
    {
        $sensitiveKeys = [
            'api_key',
            'apikey',
            'key',
            'token',
            'secret',
            'password',
            'authorization',
            'credential',
            'private_key',
            'access_token',
            'refresh_token',
        ];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string) $key);
            
            if (in_array($lowerKey, $sensitiveKeys, true)) {
                $data[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $data[$key] = ai_sanitize_log($value);
            }
        }

        return $data;
    }
}

if (!function_exists('ai_mask_key')) {
    /**
     * API Key 脱敏显示
     *
     * 保留前 4 位和后 4 位，中间用 * 替代
     *
     * @param string $key API Key
     * @return string 脱敏后的 Key
     *
     * @example
     * ```php
     * echo ai_mask_key('sk-1234567890abcdef');
     * // sk-1****cdef
     * ```
     */
    function ai_mask_key(string $key): string
    {
        $length = strlen($key);
        
        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($key, 0, 4) . str_repeat('*', $length - 8) . substr($key, -4);
    }
}

if (!function_exists('ai_truncate_messages')) {
    /**
     * 智能裁剪消息历史
     *
     * 根据 Token 限制裁剪消息，保留系统消息
     *
     * @param array $messages 消息列表
     * @param int $maxTokens 最大 Token 数
     * @return array 裁剪后的消息
     */
    function ai_truncate_messages(array $messages, int $maxTokens = 4000): array
    {
        $systemMessages = [];
        $otherMessages = [];
        
        foreach ($messages as $message) {
            if (is_array($message) && ($message['role'] ?? '') === 'system') {
                $systemMessages[] = $message;
            } else {
                $otherMessages[] = $message;
            }
        }

        $systemTokens = array_sum(array_map(
            fn($m) => ai_token_count($m['content'] ?? ''),
            $systemMessages
        ));

        $remainingTokens = $maxTokens - $systemTokens;
        $selectedMessages = [];
        $currentTokens = 0;

        foreach (array_reverse($otherMessages) as $message) {
            $tokens = ai_token_count($message['content'] ?? '');
            
            if ($currentTokens + $tokens <= $remainingTokens) {
                array_unshift($selectedMessages, $message);
                $currentTokens += $tokens;
            } else {
                break;
            }
        }

        return array_merge($systemMessages, $selectedMessages);
    }
}

if (!function_exists('ai_format_duration')) {
    /**
     * 格式化持续时间
     *
     * @param float $seconds 秒数
     * @return string 格式化后的时间
     */
    function ai_format_duration(float $seconds): string
    {
        if ($seconds < 0.001) {
            return sprintf('%.2f μs', $seconds * 1000000);
        }
        
        if ($seconds < 1) {
            return sprintf('%.2f ms', $seconds * 1000);
        }
        
        if ($seconds < 60) {
            return sprintf('%.2f s', $seconds);
        }
        
        return sprintf('%.2f m', $seconds / 60);
    }
}

if (!function_exists('ai_format_tokens')) {
    /**
     * 格式化 Token 数量
     *
     * @param int $tokens Token 数量
     * @return string 格式化后的数量
     */
    function ai_format_tokens(int $tokens): string
    {
        if ($tokens < 1000) {
            return (string) $tokens;
        }
        
        if ($tokens < 1000000) {
            return sprintf('%.1fK', $tokens / 1000);
        }
        
        return sprintf('%.2fM', $tokens / 1000000);
    }
}

if (!function_exists('ai_multimodal_generate')) {
    /**
     * 智能多模态生成（快速方法）
     *
     * @param string $prompt 提示词
     * @param array $options 选项
     * @return mixed
     *
     * @example
     * ```php
     * $result = ai_multimodal_generate('一只可爱的猫咪', ['output_type' => 'image']);
     * ```
     */
    function ai_multimodal_generate(string $prompt, array $options = []): mixed
    {
        return \Kode\AiAgent\Support\Facade\Multimodal::generate($prompt, $options);
    }
}

if (!function_exists('ai_generate_image')) {
    /**
     * 文本生成图像（快速方法）
     *
     * @param string $prompt 提示词
     * @param array $options 选项
     * @return \Kode\AiAgent\Domain\Model\ImageResponse
     *
     * @example
     * ```php
     * $response = ai_generate_image('一只可爱的猫咪');
     * echo $response->image();
     * ```
     */
    function ai_generate_image(string $prompt, array $options = []): \Kode\AiAgent\Domain\Model\ImageResponse
    {
        return \Kode\AiAgent\Support\Facade\Multimodal::generateImage($prompt, $options);
    }
}

if (!function_exists('ai_generate_video')) {
    /**
     * 文本生成视频（快速方法）
     *
     * @param string $prompt 提示词
     * @param array $options 选项
     * @return \Kode\AiAgent\Domain\Model\VideoResponse
     *
     * @example
     * ```php
     * $response = ai_generate_video('一只可爱的猫咪在玩耍');
     * echo $response->video();
     * ```
     */
    function ai_generate_video(string $prompt, array $options = []): \Kode\AiAgent\Domain\Model\VideoResponse
    {
        return \Kode\AiAgent\Support\Facade\Multimodal::generateVideo($prompt, $options);
    }
}

if (!function_exists('ai_generate_avatar')) {
    /**
     * 生成数字人视频（快速方法）
     *
     * @param string $text 口语文本
     * @param array $options 选项
     * @return \Kode\AiAgent\Domain\Model\AvatarResponse
     *
     * @example
     * ```php
     * $response = ai_generate_avatar('大家好，欢迎使用数字人！');
     * echo $response->video();
     * ```
     */
    function ai_generate_avatar(string $text, array $options = []): \Kode\AiAgent\Domain\Model\AvatarResponse
    {
        return \Kode\AiAgent\Support\Facade\Multimodal::generateAvatar($text, $options);
    }
}

if (!function_exists('ai_list_avatars')) {
    /**
     * 获取数字人列表（快速方法）
     *
     * @param array $options 选项
     * @return array
     *
     * @example
     * ```php
     * $avatars = ai_list_avatars();
     * foreach ($avatars as $avatar) {
     *     echo $avatar['name'];
     * }
     * ```
     */
    function ai_list_avatars(array $options = []): array
    {
        return \Kode\AiAgent\Support\Facade\Multimodal::listAvatars($options);
    }
}

if (!function_exists('ai_list_voices')) {
    /**
     * 获取声音列表（快速方法）
     *
     * @param array $options 选项
     * @return array
     *
     * @example
     * ```php
     * $voices = ai_list_voices();
     * foreach ($voices as $voice) {
     *     echo $voice['name'];
     * }
     * ```
     */
    function ai_list_voices(array $options = []): array
    {
        return \Kode\AiAgent\Support\Facade\Multimodal::listVoices($options);
    }
}

if (!function_exists('ai_get_progress')) {
    /**
     * 获取任务进度（快速方法）
     *
     * @param string $taskId 任务 ID
     * @return \Kode\AiAgent\Domain\Model\Progress
     *
     * @example
     * ```php
     * $progress = ai_get_progress('task-123');
     * echo $progress->status();
     * ```
     */
    function ai_get_progress(string $taskId): \Kode\AiAgent\Domain\Model\Progress
    {
        return \Kode\AiAgent\Support\Facade\Multimodal::getProgress($taskId);
    }
}

if (!function_exists('ai_format_file_size')) {
    /**
     * 格式化文件大小
     *
     * @param int $bytes 字节数
     * @return string 格式化后的大小
     *
     * @example
     * ```php
     * echo ai_format_file_size(1048576); // "1.00 MB"
     * ```
     */
    function ai_format_file_size(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        
        return sprintf('%.2f %s', $bytes, $units[$pow]);
    }
}

if (!function_exists('ai_drama_team')) {
    /**
     * 获取短剧团队实例（快速方法）
     *
     * @param string|array $apiKey API Key
     * @param array $config 配置
     * @return \Kode\AiAgent\Agent\ShortDramaTeam
     *
     * @example
     * ```php
     * $team = ai_drama_team('sk-api-key');
     * $result = $team->generate('友情主题短剧');
     * ```
     */
    function ai_drama_team(string|array $apiKey, array $config = []): \Kode\AiAgent\Agent\ShortDramaTeam
    {
        return new \Kode\AiAgent\Agent\ShortDramaTeam($apiKey, $config);
    }
}

if (!function_exists('ai_generate_drama')) {
    /**
     * 一键生成短剧（快速方法）
     *
     * @param string $topic 主题
     * @param array $options 选项
     * @return array 生成结果
     *
     * @example
     * ```php
     * $result = ai_generate_drama('友情主题', [
     *     'scenes' => 5,
     *     'style' => 'cinematic',
     * ]);
     * echo $result['final_video'];
     * ```
     */
    function ai_generate_drama(string $topic, array $options = []): array
    {
        $team = ai_drama_team(
            $options['api_key'] ?? getenv('OPENAI_API_KEY') ?: '',
            $options
        );

        if (isset($options['callback'])) {
            foreach ($options['callback'] as $event => $handler) {
                $team->on($event, $handler);
            }
        }

        return $team->generate($topic, $options);
    }
}

if (!function_exists('ai_agent_team')) {
    /**
     * 获取 Agent 团队实例（快速方法）
     *
     * @return \Kode\AiAgent\Agent\RoleAgentTeam
     *
     * @example
     * ```php
     * $team = ai_agent_team();
     * $team->assign('编剧', $writerAgent);
     * $team->assign('画师', $artistAgent);
     * $team->run('制作短剧', [...]);
     * ```
     */
    function ai_agent_team(): \Kode\AiAgent\Agent\RoleAgentTeam
    {
        return new \Kode\AiAgent\Agent\RoleAgentTeam();
    }
}

if (!function_exists('ai_supervisor')) {
    /**
     * 获取主管 Agent 实例（快速方法）
     *
     * @param \Kode\AiAgent\Agent\Agent|null $supervisor 主管 Agent
     * @return \Kode\AiAgent\Agent\SupervisorAgent
     *
     * @example
     * ```php
     * $supervisor = ai_supervisor($chiefAgent);
     * $supervisor->register('executor', $executorAgent);
     * $result = $supervisor->supervise('完成项目', [...]);
     * ```
     */
    function ai_supervisor(?\Kode\AiAgent\Agent\Agent $supervisor = null): \Kode\AiAgent\Agent\SupervisorAgent
    {
        return new \Kode\AiAgent\Agent\SupervisorAgent($supervisor);
    }
}

if (!function_exists('ai_validate_media_file')) {
    /**
     * 验证媒体文件
     *
     * @param string $filePath 文件路径
     * @param string $type 文件类型 (image|video|audio)
     * @param int $maxSize 最大文件大小（字节）
     * @return bool 是否有效
     *
     * @example
     * ```php
     * if (ai_validate_media_file('/path/to/video.mp4', 'video', 104857600)) {
     *     // 文件有效
     * }
     * ```
     */
    function ai_validate_media_file(string $filePath, string $type = 'image', int $maxSize = 104857600): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }
        
        if (filesize($filePath) > $maxSize) {
            return false;
        }
        
        $allowedExtensions = match ($type) {
            'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'],
            'video' => ['mp4', 'webm', 'mov', 'avi', 'mkv'],
            'audio' => ['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a'],
            default => [],
        };
        
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        return in_array($extension, $allowedExtensions, true);
    }
}
