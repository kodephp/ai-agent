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

if (!function_exists('ai_hub')) {
    /**
     * 获取 Agent Hub 实例（单 Key 多 Agent）
     *
     * @param string|null $apiKey API Key（可选，使用环境变量 OPENAI_API_KEY）
     * @param array $config 配置
     * @return \Kode\AiAgent\Agent\AgentHub
     *
     * @example
     * ```php
     * $hub = ai_hub('sk-api-key');
     *
     * // 使用编剧
     * $hub->writer()->chat('写一个剧本');
     *
     * // 使用画师
     * $hub->artist()->chat('生成一幅画');
     *
     * // 并行任务
     * $hub->parallel([
     *     ['agent' => 'writer', 'message' => '写第一幕'],
     *     ['agent' => 'writer', 'message' => '写第二幕'],
     * ]);
     * ```
     */
    function ai_hub(?string $apiKey = null, array $config = []): \Kode\AiAgent\Agent\AgentHub
    {
        $key = $apiKey ?? getenv('OPENAI_API_KEY') ?: '';
        return \Kode\AiAgent\Agent\AgentHub::create($key, $config);
    }
}

if (!function_exists('ai_script_generator')) {
    /**
     * 获取剧本生成器实例
     *
     * @param \Kode\AiAgent\Domain\Contract\AdapterInterface|null $adapter 适配器
     * @param array $defaultOptions 默认选项
     * @return \Kode\AiAgent\Agent\ScriptGenerator
     *
     * @example
     * ```php
     * $generator = ai_script_generator($adapter);
     * $script = $generator->generate('友情主题', ['scenes' => 5]);
     * $parsed = $generator->parse($script);
     * ```
     */
    function ai_script_generator(
        ?\Kode\AiAgent\Domain\Contract\AdapterInterface $adapter = null,
        array $defaultOptions = []
    ): \Kode\AiAgent\Agent\ScriptGenerator {
        if ($adapter === null) {
            $hub = ai_hub();
            $adapter = $hub->adapter();
        }
        return new \Kode\AiAgent\Agent\ScriptGenerator($adapter, $defaultOptions);
    }
}

if (!function_exists('ai_single_key_multimodal')) {
    /**
     * 单 Key 多模态生成（快捷方法）
     *
     * @param string $type 类型 (image|video|avatar)
     * @param string $prompt 提示词
     * @param array $options 选项
     * @return mixed
     *
     * @example
     * ```php
     * // 生成图像
     * ai_single_key_multimodal('image', '一只可爱的猫咪');
     *
     * // 生成视频
     * ai_single_key_multimodal('video', '风景视频');
     * ```
     */
    function ai_single_key_multimodal(string $type, string $prompt, array $options = []): mixed
    {
        $hub = ai_hub();

        return match ($type) {
            'image' => $hub->image($prompt, $options),
            'video' => $hub->video($prompt, $options),
            'script' => $hub->script($prompt, $options),
            'drama' => $hub->shortDrama($prompt, $options),
            default => throw new \InvalidArgumentException("未知类型: {$type}"),
        };
    }
}

if (!function_exists('ai_collaborate')) {
    /**
     * 多 Agent 协作（快捷方法）
     *
     * @param callable $callback 回调函数，接收 $team 和 $hub
     * @param string|null $apiKey API Key
     * @param array $config 配置
     * @return array
     *
     * @example
     * ```php
     * $result = ai_collaborate(function($team, $hub) {
     *     $team->assign('编剧', $hub->writer());
     *     $team->assign('画师', $hub->artist());
     *     return $team->run('制作短剧', [...]);
     * });
     * ```
     */
    function ai_collaborate(callable $callback, ?string $apiKey = null, array $config = []): array
    {
        $hub = ai_hub($apiKey, $config);
        return $hub->team($callback);
    }
}

if (!function_exists('ai_seedance')) {
    /**
     * 获取 Seedance 视频服务
     *
     * @param string|null $apiKey API Key（可选，使用环境变量 SEEDANCE_API_KEY）
     * @param array $options 配置
     * @return \Kode\AiAgent\Video\SeedanceService
     *
     * @example
     * ```php
     * $service = ai_seedance('your-api-key');
     *
     * // 文生视频 (默认 720P)
     * $video = $service->textToVideo('一只可爱的猫咪');
     *
     * // 文生视频 (1080P)
     * $video = $service->textToVideo('一只可爱的猫咪', [
     *     'resolution' => '1080p',
     * ]);
     *
     * // 图生视频
     * $video = $service->imageToVideo('image.jpg', '让猫咪动起来');
     *
     * // 多镜头
     * $video = $service->multiShot('风景', 3);
     * ```
     */
    function ai_seedance(?string $apiKey = null, array $options = []): \Kode\AiAgent\Video\SeedanceService
    {
        $key = $apiKey ?? getenv('SEEDANCE_API_KEY') ?: getenv('OPENAI_API_KEY') ?: '';
        return \Kode\AiAgent\Video\SeedanceService::create($key, $options);
    }
}

if (!function_exists('ai_video')) {
    /**
     * 快速生成视频（快捷方法）
     *
     * @param string $prompt 提示词
     * @param array $options 选项
     * @return \Kode\AiAgent\Domain\Model\VideoResponse
     *
     * @example
     * ```php
     * // 720P 视频
     * $video = ai_video('一只猫咪在草地上玩耍');
     *
     * // 1080P 视频
     * $video = ai_video('一只猫咪在草地上玩耍', ['resolution' => '1080p']);
     *
     * // 纵向视频
     * $video = ai_video('舞蹈表演', ['aspect_ratio' => '9:16']);
     * ```
     */
    function ai_video(string $prompt, array $options = []): \Kode\AiAgent\Domain\Model\VideoResponse
    {
        return ai_seedance(null, $options)->textToVideo($prompt, $options);
    }
}

if (!function_exists('ai_image_to_video')) {
    /**
     * 图生视频（快捷方法）
     *
     * @param string $image 图像
     * @param string|null $prompt 提示词
     * @param array $options 选项
     * @return \Kode\AiAgent\Domain\Model\VideoResponse
     *
     * @example
     * ```php
     * $video = ai_image_to_video('photo.jpg', '让照片动起来');
     * ```
     */
    function ai_image_to_video(string $image, ?string $prompt = null, array $options = []): \Kode\AiAgent\Domain\Model\VideoResponse
    {
        return ai_seedance(null, $options)->imageToVideo($image, $prompt, $options);
    }
}

if (!function_exists('ai_moe')) {
    /**
     * 获取 MOE 网关实例
     *
     * 单 Key 多模型智能路由网关。后台申请各平台 Key，
     * 用户只感知一个网关，自动选择最优模型。
     *
     * @return \Kode\AiAgent\Moe\MoEGateway
     *
     * @example
     * ```php
     * $gateway = ai_moe();
     * $gateway->addExpert('openai', env('OPENAI_API_KEY'), ['chat', 'vision']);
     * $gateway->addExpert('deepseek', env('DEEPSEEK_API_KEY'), ['chat', 'code']);
     * $response = $gateway->chat('你好');
     * ```
     */
    function ai_moe(): \Kode\AiAgent\Moe\MoEGateway
    {
        return \Kode\AiAgent\Support\Facade\MoE::gateway();
    }
}

if (!function_exists('ai_moe_chat')) {
    /**
     * MOE 智能聊天（自动路由）
     *
     * @param string $message 用户消息
     * @param array $options 选项（capability, preferred_platform 等）
     * @return \Kode\AiAgent\Domain\Contract\ResponseInterface
     *
     * @example
     * ```php
     * $response = ai_moe_chat('分析这段代码', ['capability' => 'code']);
     * ```
     */
    function ai_moe_chat(string $message, array $options = []): \Kode\AiAgent\Domain\Contract\ResponseInterface
    {
        return \Kode\AiAgent\Support\Facade\MoE::chat($message, $options);
    }
}

if (!function_exists('ai_smart_chat')) {
    /**
     * MOE 一键智能聊天（自动压缩 + Token 均衡路由）
     *
     * 最简入口：用户无需关心模型、Token、压缩，系统全自动处理。
     *
     * @param string $message 用户消息
     * @param array $options 选项
     * @return \Kode\AiAgent\Domain\Contract\ResponseInterface
     *
     * @example
     * ```php
     * $response = ai_smart_chat('帮我写一份产品需求文档');
     * ```
     */
    function ai_smart_chat(string $message, array $options = []): \Kode\AiAgent\Domain\Contract\ResponseInterface
    {
        return \Kode\AiAgent\Support\Facade\MoE::smartChat($message, $options);
    }
}

if (!function_exists('ai_compress_prompt')) {
    /**
     * 压缩 Prompt，降低 Token 消耗（基于技能链）
     *
     * @param string $prompt 原始 Prompt
     * @param int|null $maxTokens 目标最大 Token 数
     * @return string 压缩后的 Prompt
     *
     * @example
     * ```php
     * $compressed = ai_compress_prompt($longPrompt, maxTokens: 2000);
     * ```
     */
    function ai_compress_prompt(string $prompt, ?int $maxTokens = null): string
    {
        static $compressor = null;
        $compressor ??= new \Kode\AiAgent\Token\SkillBasedCompressor();
        return $compressor->compress($prompt, $maxTokens);
    }
}

if (!function_exists('ai_compress_savings')) {
    /**
     * 计算 Prompt 压缩可节省的 Token
     *
     * @param string $prompt 原始 Prompt
     * @param int|null $maxTokens 目标最大 Token 数
     * @return array{original: int, compressed: int, saved: int, ratio: float, skills: array<int, string>}
     *
     * @example
     * ```php
     * $savings = ai_compress_savings($longPrompt, maxTokens: 2000);
     * ```
     */
    function ai_compress_savings(string $prompt, ?int $maxTokens = null): array
    {
        static $compressor = null;
        $compressor ??= new \Kode\AiAgent\Token\SkillBasedCompressor();
        return $compressor->savings($prompt, $maxTokens);
    }
}

if (!function_exists('ai_token_balance_report')) {
    /**
     * 生成多模型 Token 消耗对比报告
     *
     * @param array<int, string> $models 候选模型
     * @param string $text 文本
     * @return array<int, array{model: string, estimated_tokens: int, equivalent_tokens: int, cost_index: float}>
     *
     * @example
     * ```php
     * $report = ai_token_balance_report(['gpt-4o', 'deepseek-chat'], '你好世界');
     * ```
     */
    function ai_token_balance_report(array $models, string $text): array
    {
        static $balancer = null;
        $balancer ??= new \Kode\AiAgent\Token\TokenBalancer();
        return $balancer->report($models, $text);
    }
}

if (!function_exists('ai_recommend_model')) {
    /**
     * 根据文本推荐最省 Token 的模型
     *
     * @param array<int, string> $models 候选模型
     * @param string $text 文本
     * @return string 推荐模型
     *
     * @example
     * ```php
     * $model = ai_recommend_model(['gpt-4o', 'deepseek-chat'], '你好世界');
     * ```
     */
    function ai_recommend_model(array $models, string $text): string
    {
        static $balancer = null;
        $balancer ??= new \Kode\AiAgent\Token\TokenBalancer();
        return $balancer->recommendMostEfficient($models, $text);
    }
}

if (!function_exists('ai_token_estimate')) {
    /**
     * 估算文本的 Token 数
     *
     * @param string $text 文本
     * @return int Token 数估算
     *
     * @example
     * ```php
     * $tokens = ai_token_estimate('你好世界');
     * ```
     */
    function ai_token_estimate(string $text): int
    {
        static $counter = null;
        $counter ??= new \Kode\AiAgent\Token\TokenCounter();
        return $counter->estimate($text);
    }
}

if (!function_exists('ai_pii_mask')) {
    /**
     * 脱敏文本中的个人敏感信息
     *
     * @param string $text 原始文本
     * @return string 脱敏后的文本
     *
     * @example
     * ```php
     * $safe = ai_pii_mask('我的手机是13800138000');
     * // 输出: "我的手机是138****8000"
     * ```
     */
    function ai_pii_mask(string $text): string
    {
        static $detector = null;
        $detector ??= new \Kode\AiAgent\Security\PiiDetector();
        return $detector->mask($text);
    }
}

if (!function_exists('ai_check_injection')) {
    /**
     * 检查文本是否包含提示词注入攻击
     *
     * @param string $text 待检查文本
     * @return bool 是否安全（true 表示安全）
     *
     * @example
     * ```php
     * if (!ai_check_injection($userInput)) {
     *     throw new \Exception('检测到提示词注入');
     * }
     * ```
     */
    function ai_check_injection(string $text): bool
    {
        static $detector = null;
        $detector ??= new \Kode\AiAgent\Security\PromptInjectionDetector();
        return !$detector->isMalicious($text);
    }
}
