<?php

// 检查是否有msg参数传入
if (!isset($_GET['msg'])) {
    exit(); // 没有msg参数，不输出任何内容
}

$msg = $_GET['msg']; // 解析msg参数
$parts = explode(' ', $msg);
$triggerWord = array_shift($parts); // 提取触发词
$params = $parts; // 剩余部分作为参数

$userIP = $_SERVER['REMOTE_ADDR']; // 用户IP地址
$currentTime = date("H:i"); // 当前时间，格式为时:分

$spContent = file_get_contents('./index.sp'); // 读取并解析SP文件
$blocks = preg_split("/\n\s*\n/", $spContent); // 分割成代码块，允许多个换行作为分隔

foreach ($blocks as $block) {
    $lines = explode("\n", trim($block));
    $blockTrigger = trim(array_shift($lines)); // 提取每个代码块的触发词

    if (preg_match("/$blockTrigger/", $triggerWord)) {
        $output = processBlock($lines, $params, $triggerWord, $currentTime, $userIP);
        echo $output;
        break;
    }
}

function executeGetRequest($url) {
    $url = substr($url, 0, -1);
    return file_get_contents($url);
}

function processBlock($lines, $params, $triggerWord, $currentTime, $userIP) {
    $variables = [];
    $output = '';
    $processingIf = false;
    $ifConditionMet = false;

    foreach ($lines as $line) {
        $line = str_replace(['%时间%', '%UIP%', '%触发词%'], [$currentTime, $userIP, $triggerWord], $line);

        if (preg_match('/^\$GET (.+)$/', $line, $matches)) {
            $url = replaceParamsAndVariables($matches[1], $params, $variables);
            $response = executeGetRequest($url);
            $output .= $response . "\n";
            continue;
        }

        if (preg_match('/^%(.+)% = (.+)$/', $line, $matches)) {
            $variables[$matches[1]] = evaluateExpression($matches[2], $params, $variables);
            continue;
        }

        if (preg_match('/^如果: (.+)$/', $line, $matches)) {
            $processingIf = true;
            $condition = evaluateCondition($matches[1], $params, $variables);
            $ifConditionMet = $condition;
            continue;
        }

        if (preg_match('/^如果尾$/', $line)) {
            $processingIf = false;
            continue;
        }

        if ($processingIf && !$ifConditionMet) {
            continue;
        }

        $line = replaceParamsAndVariables($line, $params, $variables);
        $output .= $line . "\n";
    }

    return $output;
}

function replaceParamsAndVariables($line, $params, $variables) {
    $line = str_replace('%参数%', implode(' ', $params), $line);
    foreach ($params as $index => $param) {
        $line = str_replace("%参数" . ($index + 1) . "%", $param, $line);
    }
    foreach ($variables as $varName => $varValue) {
        $line = str_replace("%$varName%", $varValue, $line);
    }
    return $line;
}

function evaluateExpression($expression, $params, $variables) {
    $expression = replaceParamsAndVariables($expression, $params, $variables);
    eval('$result = ' . $expression . ';');
    return $result;
}

function evaluateCondition($condition, $params, $variables) {
    $condition = replaceParamsAndVariables($condition, $params, $variables);
    $condition = preg_replace_callback('/([^\s]+)\s*==\s*([^\s]+)/', function ($matches) {
        $left = $matches[1];
        $right = $matches[2];

        if (!preg_match('/^["\'].*["\']$/', trim($left))) {
            $left = "'" . $left . "'";
        }

        if (!preg_match('/^["\'].*["\']$/', trim($right))) {
            $right = "'" . $right . "'";
        }

        return $left . " == " . $right;
    }, $condition);

    eval('$result = (' . $condition . ') ? true : false;');
    return $result;
}

?>
