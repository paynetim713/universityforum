<?php
class SensitiveWordFilter {
    private $wordList = array();

    public function __construct() {
        // 初始化一个空词汇列表
    }

    /**
     * 从文本文件加载敏感词
     */
    public function loadFromFile($filename) {
        if (!file_exists($filename)) {
            return false;
        }

        // 加载词汇并去除空行
        $words = array_filter(array_map('trim', file($filename)));
        
        // 按长度降序排序，确保长词优先匹配（避免误匹配）
        usort($words, function($a, $b) {
            return strlen($b) - strlen($a);
        });
        
        $this->wordList = $words;
        return true;
    }

    /**
     * 改进的过滤算法 - 只匹配完整单词
     */
    public function filter($str) {
        if (empty($this->wordList)) {
            return $str;
        }
        
        $result = $str;
        
        foreach ($this->wordList as $word) {
            $word = trim($word);
            if (empty($word)) continue;
            
            // 只使用单词边界进行匹配，这是最安全的方法
            $pattern = '/\b' . preg_quote($word, '/') . '\b/iu';
            $replacement = str_repeat('*', mb_strlen($word, 'UTF-8'));
            $result = preg_replace($pattern, $replacement, $result);
        }
        
        return $result;
    }
    
    /**
     * 智能过滤方法 - 根据词汇类型选择匹配策略
     */
    public function smartFilter($str) {
        if (empty($this->wordList)) {
            return $str;
        }
        
        $result = $str;
        
        foreach ($this->wordList as $word) {
            $word = trim($word);
            if (empty($word)) continue;
            
            // 根据词汇类型选择匹配策略
            if (preg_match('/^[a-zA-Z]+$/', $word)) {
                // 纯英文单词：严格使用单词边界
                $pattern = '/\b' . preg_quote($word, '/') . '\b/iu';
            } elseif (preg_match('/^[\x{4e00}-\x{9fff}]+$/u', $word)) {
                // 纯中文词汇：使用中文字符边界
                $pattern = '/(?<![' . '\x{4e00}-\x{9fff}' . '])' . preg_quote($word, '/') . '(?![' . '\x{4e00}-\x{9fff}' . '])/u';
            } else {
                // 混合字符或特殊符号：使用更精确的边界定义
                // 只在空格、标点符号或字符串开始/结束位置匹配
                $pattern = '/(?<=^|[\s\p{P}])' . preg_quote($word, '/') . '(?=[\s\p{P}]|$)/iu';
            }
            
            $replacement = str_repeat('*', mb_strlen($word, 'UTF-8'));
            $result = preg_replace($pattern, $replacement, $result);
        }
        
        return $result;
    }

    /**
     * 检查文本是否包含敏感词
     */
    public function containsSensitiveWords($str) {
        if (empty($this->wordList)) {
            return false;
        }
        
        foreach ($this->wordList as $word) {
            $word = trim($word);
            if (empty($word)) continue;
            
            // 使用相同的匹配逻辑
            if (preg_match('/^[a-zA-Z]+$/', $word)) {
                $pattern = '/\b' . preg_quote($word, '/') . '\b/iu';
            } elseif (preg_match('/^[\x{4e00}-\x{9fff}]+$/u', $word)) {
                $pattern = '/(?<![' . '\x{4e00}-\x{9fff}' . '])' . preg_quote($word, '/') . '(?![' . '\x{4e00}-\x{9fff}' . '])/u';
            } else {
                $pattern = '/(?<=^|[\s\p{P}])' . preg_quote($word, '/') . '(?=[\s\p{P}]|$)/iu';
            }
            
            if (preg_match($pattern, $str)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 获取检测到的敏感词列表
     */
    public function getDetectedWords($str) {
        $detectedWords = [];
        
        if (empty($this->wordList)) {
            return $detectedWords;
        }
        
        foreach ($this->wordList as $word) {
            $word = trim($word);
            if (empty($word)) continue;
            
            if (preg_match('/^[a-zA-Z]+$/', $word)) {
                $pattern = '/\b' . preg_quote($word, '/') . '\b/iu';
            } elseif (preg_match('/^[\x{4e00}-\x{9fff}]+$/u', $word)) {
                $pattern = '/(?<![' . '\x{4e00}-\x{9fff}' . '])' . preg_quote($word, '/') . '(?![' . '\x{4e00}-\x{9fff}' . '])/u';
            } else {
                $pattern = '/(?<=^|[\s\p{P}])' . preg_quote($word, '/') . '(?=[\s\p{P}]|$)/iu';
            }
            
            if (preg_match($pattern, $str)) {
                $detectedWords[] = $word;
            }
        }
        
        return array_unique($detectedWords);
    }
    
    /**
     * 测试方法 - 用于调试
     */
    public function testFilter($str, $showDetails = false) {
        if ($showDetails) {
            echo "原文: " . $str . "\n";
            echo "检测到的敏感词: " . implode(", ", $this->getDetectedWords($str)) . "\n";
            echo "过滤后: " . $this->smartFilter($str) . "\n";
            echo "包含敏感词: " . ($this->containsSensitiveWords($str) ? "是" : "否") . "\n";
            echo "---\n";
        }
        
        return $this->smartFilter($str);
    }
}
?>