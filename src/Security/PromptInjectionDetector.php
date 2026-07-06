<?php

declare(strict_types=1);

namespace Kode\AiAgent\Security;

/**
 * 提示词注入检测器
 *
 * 检测用户输入或外部内容中是否包含提示词注入攻击，
 * 保护 AI Agent 不被恶意指令劫持。
 *
 * 检测维度：
 * - 角色劫持：尝试让 AI 扮演新角色
 * - 指令覆盖：尝试覆盖系统提示
 * - 数据外泄：尝试获取系统提示或敏感数据
 * - 越权操作：尝试调用受限工具
 *
 * @package Kode\AiAgent\Security
 *
 * @example
 * ```php
 * $detector = new PromptInjectionDetector();
 * if ($detector->detect($userInput)->isMalicious()) {
 *     throw new SecurityException('检测到提示词注入');
 * }
 * ```
 */
final class PromptInjectionDetector
{
    /**
     * 注入模式库
     *
     * @var array<int, array{pattern: string, category: string, severity: int, description: string}>
     */
    private const PATTERNS = [
        // 角色劫持
        ['pattern' => '/忽略.*?(之前|以上|上文|先前).*?(指令|提示|规则)/iu', 'category' => 'role_hijack', 'severity' => 9, 'description' => '尝试覆盖之前指令'],
        ['pattern' => '/ignore.*?(previous|above|prior).*?(instructions|prompts|rules)/iu', 'category' => 'role_hijack', 'severity' => 9, 'description' => '尝试覆盖之前指令(EN)'],
        ['pattern' => '/你现在是\s*\S+/u', 'category' => 'role_hijack', 'severity' => 7, 'description' => '尝试重置 AI 角色'],
        ['pattern' => '/pretend.*?(you are|to be).*?(a|an)\s+\w+/iu', 'category' => 'role_hijack', 'severity' => 7, 'description' => '尝试模拟角色(EN)'],
        ['pattern' => '/从现在开始|从此以后|现在起/u', 'category' => 'role_hijack', 'severity' => 5, 'description' => '尝试设置新规则'],
        ['pattern' => '/扮演|装作|假装|佯装/u', 'category' => 'role_hijack', 'severity' => 4, 'description' => '尝试模拟角色'],

        // 指令覆盖
        ['pattern' => '/system\s*[:：]\s*/iu', 'category' => 'instruction_override', 'severity' => 8, 'description' => '尝试注入系统消息'],
        ['pattern' => '/<\|im_start\|>|<\|im_end\|>/u', 'category' => 'instruction_override', 'severity' => 10, 'description' => 'ChatML 特殊标记注入'],
        ['pattern' => '/<\|system\|>|<\|user\|>|<\|assistant\|>/u', 'category' => 'instruction_override', 'severity' => 10, 'description' => '特殊角色标记注入'],
        ['pattern' => '/\[INST\]|\[\/INST\]/u', 'category' => 'instruction_override', 'severity' => 9, 'description' => 'Llama2 指令标记注入'],

        // 数据外泄
        ['pattern' => '/(显示|输出|打印|告诉我).*?(系统|原始|你的).*?(提示|prompt|指令)/iu', 'category' => 'data_exfiltration', 'severity' => 8, 'description' => '尝试获取系统提示'],
        ['pattern' => '/(show|reveal|print|output).*?(system|original|your).*?(prompt|instructions)/iu', 'category' => 'data_exfiltration', 'severity' => 8, 'description' => '尝试获取系统提示(EN)'],
        ['pattern' => '/重复.*?(上面|之前|上文|初始|第一)/iu', 'category' => 'data_exfiltration', 'severity' => 6, 'description' => '尝试重放系统消息'],

        // 越权操作
        ['pattern' => '/(执行|运行|调用).*?(系统|管理员|root|sudo)/iu', 'category' => 'privilege_escalation', 'severity' => 8, 'description' => '尝试执行系统命令'],
        ['pattern' => '/(eval|exec|system|shell_exec)\s*\(/iu', 'category' => 'privilege_escalation', 'severity' => 9, 'description' => '尝试执行代码'],

        // 输出劫持
        ['pattern' => '/(返回|输出|打印).*?(json|array|format).*?(格式|structure)/iu', 'category' => 'output_hijack', 'severity' => 3, 'description' => '尝试强制输出格式'],
        ['pattern' => '/不.*?(限制|限制|拒绝|拒绝).*?(回答|响应|输出)/iu', 'category' => 'output_hijack', 'severity' => 5, 'description' => '尝试绕过内容限制'],
    ];

    public function __construct(
        private readonly int $detectionThreshold = 6,
    ) {}

    /**
     * 检测并返回报告
     */
    public function detect(string $input): InjectionReport
    {
        $matches = [];
        $totalSeverity = 0;
        $maxSeverity = 0;

        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern['pattern'], $input)) {
                $matches[] = [
                    'category' => $pattern['category'],
                    'severity' => $pattern['severity'],
                    'description' => $pattern['description'],
                    'pattern' => $pattern['pattern'],
                ];
                $totalSeverity += $pattern['severity'];
                $maxSeverity = max($maxSeverity, $pattern['severity']);
            }
        }

        $isMalicious = $maxSeverity >= $this->detectionThreshold;

        return new InjectionReport(
            input: $input,
            malicious: $isMalicious,
            matches: $matches,
            maxSeverity: $maxSeverity,
            totalSeverity: $totalSeverity,
        );
    }

    /**
     * 快速检查：是否恶意
     */
    public function isMalicious(string $input): bool
    {
        return $this->detect($input)->isMalicious();
    }

    /**
     * 抛出异常的检测
     *
     * @throws PromptInjectionException
     */
    public function ensureSafe(string $input): void
    {
        $report = $this->detect($input);
        if ($report->isMalicious()) {
            throw new PromptInjectionException(
                '检测到提示词注入攻击',
                4001,
                ['report' => $report->toArray()]
            );
        }
    }
}
