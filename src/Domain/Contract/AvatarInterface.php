<?php

declare(strict_types=1);

namespace Kode\AiAgent\Domain\Contract;

use Kode\AiAgent\Domain\Model\AvatarResponse;
use Kode\AiAgent\Domain\Model\Progress;
use Kode\AiAgent\Domain\ValueObject\MediaFile;
use Kode\AiAgent\Exception\PlatformException;
use Kode\AiAgent\Exception\InvalidInputException;

/**
 * 数字人接口
 * 
 * 定义数字人相关API的统一接口，支持自定义数字人、声音上传和视频生成。
 * 
 * @package Kode\AiAgent\Domain\Contract
 */
interface AvatarInterface
{
    /**
     * 生成数字人视频
     *
     * @param string $text 口语文本
     * @param array{
     *     avatar_id?: string,
     *     voice_id?: string,
     *     language?: string,
     *     background?: string,
     *     resolution?: string,
     *     aspect_ratio?: string,
     *     speed?: float,
     *     expression?: string,
     * } $options 可选参数
     *
     * @return AvatarResponse 数字人响应
     *
     * @throws PlatformException 当平台调用失败时
     * @throws InvalidInputException 当输入无效时
     */
    #[\NoDiscard]
    public function generateAvatarVideo(string $text, array $options = []): AvatarResponse;

    /**
     * 使用自定义视频生成数字人
     *
     * @param string $text 口语文本
     * @param MediaFile $videoFile 用户上传的自定义视频文件
     * @param array{
     *     voice_id?: string,
     *     language?: string,
     *     background?: string,
     *     resolution?: string,
     *     aspect_ratio?: string,
     *     speed?: float,
     * } $options 可选参数
     *
     * @return AvatarResponse 数字人响应
     *
     * @throws PlatformException 当平台调用失败时
     * @throws InvalidInputException 当输入无效时
     */
    #[\NoDiscard]
    public function generateWithCustomVideo(string $text, MediaFile $videoFile, array $options = []): AvatarResponse;

    /**
     * 使用自定义音频生成数字人
     *
     * @param MediaFile $audioFile 用户上传的自定义音频文件
     * @param array{
     *     avatar_id?: string,
     *     background?: string,
     *     resolution?: string,
     *     aspect_ratio?: string,
     * } $options 可选参数
     *
     * @return AvatarResponse 数字人响应
     *
     * @throws PlatformException 当平台调用失败时
     * @throws InvalidInputException 当输入无效时
     */
    #[\NoDiscard]
    public function generateWithCustomAudio(MediaFile $audioFile, array $options = []): AvatarResponse;

    /**
     * 异步生成数字人视频
     *
     * @param string $text 口语文本
     * @param array{
     *     avatar_id?: string,
     *     voice_id?: string,
     *     callback_url?: string,
     * } $options 可选参数
     *
     * @return string 任务 ID
     *
     * @throws PlatformException 当平台调用失败时
     * @throws InvalidInputException 当输入无效时
     */
    #[\NoDiscard]
    public function generateAvatarVideoAsync(string $text, array $options = []): string;

    /**
     * 查询任务进度
     *
     * @param string $taskId 任务 ID
     *
     * @return Progress 任务进度
     *
     * @throws PlatformException 当查询失败时
     */
    #[\NoDiscard]
    public function getProgress(string $taskId): Progress;

    /**
     * 获取可用数字人列表
     *
     * @param array{
     *     page?: int,
     *     page_size?: int,
     *     category?: string,
     *     gender?: string,
     *     language?: string,
     * } $options 可选参数
     *
     * @return array 数字人列表
     */
    #[\NoDiscard]
    public function listAvatars(array $options = []): array;

    /**
     * 获取可用声音列表
     *
     * @param array{
     *     page?: int,
     *     page_size?: int,
     *     language?: string,
     *     gender?: string,
     *     style?: string,
     * } $options 可选参数
     *
     * @return array 声音列表
     */
    #[\NoDiscard]
    public function listVoices(array $options = []): array;

    /**
     * 获取数字人详情
     *
     * @param string $avatarId 数字人 ID
     *
     * @return array 数字人详情
     *
     * @throws PlatformException 当查询失败时
     */
    #[\NoDiscard]
    public function getAvatar(string $avatarId): array;

    /**
     * 获取声音详情
     *
     * @param string $voiceId 声音 ID
     *
     * @return array 声音详情
     *
     * @throws PlatformException 当查询失败时
     */
    #[\NoDiscard]
    public function getVoice(string $voiceId): array;

    /**
     * 获取下载提示信息
     *
     * @param AvatarResponse $response 数字人响应
     *
     * @return string 用户提示信息
     */
    public function getDownloadPrompt(AvatarResponse $response): string;
}
