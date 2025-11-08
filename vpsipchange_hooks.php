<?php
use WHMCS\Database\Capsule;

add_hook('InvoicePaid', 1, function($vars) {
    $invoiceId = $vars['invoiceid'];

    try {
        $items = Capsule::table('tblinvoiceitems')->where('invoiceid', $invoiceId)->get();

        foreach ($items as $item) {
            if (strpos($item->description, 'VPS更换IP费用') !== false) {
                if (preg_match('/VPS ID:\s*(\d+)/', $item->description, $matches)) {
                    $vps_id = (int)$matches[1];
                    
                    $targetPool = null;
                    if (preg_match('/目标IP池:\s*(\d+)/', $item->description, $poolMatches)) {
                        $targetPool = (int)$poolMatches[1];
                    }
                    
                    logActivity("检测到IP更换支付，VPS ID: {$vps_id}, 目标池: " . ($targetPool ?: '自动'));

                    $existing = Capsule::table('mod_vpsipchange_queue')
                        ->where('vps_id', $vps_id)
                        ->where('invoiceid', $invoiceId)
                        ->where('status', 'pending')
                        ->first();
                    
                    if ($existing) {
                        logActivity("VPS {$vps_id} 已在队列中，跳过");
                        continue;
                    }
                    
                    $changeResult = changeVPSIPDirect($vps_id, $invoiceId, $item->userid, $item->relid, $targetPool);

                    if ($changeResult['success']) {
                        logActivity("VPS IP更换成功，VPS ID: {$vps_id}, {$changeResult['old_ip']} → {$changeResult['new_ip']}");
                    } else {
                        logActivity("VPS IP更换失败，VPS ID: {$vps_id}, 错误: " . $changeResult['error']);
                    }
                }
            }
        }
    } catch (Exception $e) {
        logActivity("InvoicePaid Hook错误: " . $e->getMessage());
    }
});

function changeVPSIPDirect($vps_id, $invoiceId, $userId, $hostingId, $targetPool = null) {
    $lockFile = ROOTDIR . "/tmp/vpsipchange_{$vps_id}.lock";
    
    if (!is_dir(ROOTDIR . '/tmp')) {
        mkdir(ROOTDIR . '/tmp', 0755, true);
    }
    
    if (file_exists($lockFile)) {
        $lockTime = filemtime($lockFile);
        if (time() - $lockTime > 300) {
            unlink($lockFile);
        } else {
            return ['success' => false, 'error' => 'VPS正在被其他进程处理'];
        }
    }
    
    file_put_contents($lockFile, time());
    
    try {
        $result = changeVPSIPWithWait($vps_id, $invoiceId, $userId, $hostingId, $targetPool);
        
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
        
        return $result;
        
    } catch (Exception $e) {
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
        
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function changeVPSIPWithWait($vps_id, $invoiceId, $userId, $hostingId, $targetPool = null) {
    logActivity("═══ 开始IP更换流程 ═══");
    logActivity("VPS ID: {$vps_id}, 发票: {$invoiceId}, 目标池: " . ($targetPool ?: '自动'));

    try {
        $params = getAddonModuleParams('vpsipchange');

        if (empty($params['admin_api_key']) || empty($params['admin_api_pass']) || empty($params['server_ip'])) {
            return ['success' => false, 'error' => '模块配置缺失'];
        }

        $key = $params['admin_api_key'];
        $pass = $params['admin_api_pass'];
        $ip = $params['server_ip'];
        
        $stopWaitTime = (int)($params['stop_wait_time'] ?? 10);
        $startWaitTime = (int)($params['start_wait_time'] ?? 15);
        
        logActivity("等待配置 - Stop: {$stopWaitTime}秒, Start: {$startWaitTime}秒");

        require_once(ROOTDIR . '/modules/servers/virtualizor/sdk/admin.php');
        $admin = new Virtualizor_Admin_API($ip, $key, $pass);

        // 步骤1: 获取VPS当前信息
        logActivity("步骤1: 获取VPS当前信息");
        $vpsInfo = $admin->listvs(1, 1, array('vpsid' => $vps_id));
        
        if (!isset($vpsInfo[$vps_id])) {
            return ['success' => false, 'error' => '无法获取VPS信息'];
        }

        $vps = $vpsInfo[$vps_id];
        $oldIPs = $vps['ips'] ?? [];
        
        // 分离IPv4和IPv6地址
        $oldIPv4List = [];
        $oldIPv6List = [];
        foreach ($oldIPs as $ipAddr) {
            if (filter_var($ipAddr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $oldIPv4List[] = $ipAddr;
            } elseif (filter_var($ipAddr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $oldIPv6List[] = $ipAddr;
            }
        }

        if (empty($oldIPv4List)) {
            return ['success' => false, 'error' => '未找到IPv4地址'];
        }

        $ipv4Count = count($oldIPv4List);
        logActivity("当前IPv4地址数量: {$ipv4Count}");
        logActivity("当前IPv4: " . implode(', ', $oldIPv4List));
        if (!empty($oldIPv6List)) {
            logActivity("当前IPv6(将保留): " . implode(', ', $oldIPv6List));
        }

        // 步骤2: 获取新IP - 需要获取相同数量的IPv4地址
        logActivity("步骤2: 获取{$ipv4Count}个可用的新IPv4地址");
        
        $newIPv4List = [];
        $excludeIPs = array_merge($oldIPv4List, []); // 排除旧IP和已获取的新IP
        
        for ($i = 0; $i < $ipv4Count; $i++) {
            if ($targetPool) {
                $newIP = getAvailableIPFromPool_Fixed($admin, $targetPool, $excludeIPs);
                if (!$newIP) {
                    return ['success' => false, 'error' => "IP池{$targetPool}没有足够的可用IP（需要{$ipv4Count}个，仅获取到{$i}个）"];
                }
            } else {
                $newIP = getAvailableIP_Fixed($admin, '', $excludeIPs);
                if (!$newIP) {
                    return ['success' => false, 'error' => "没有足够的可用IP（需要{$ipv4Count}个，仅获取到{$i}个）"];
                }
            }
            
            $newIPv4List[] = $newIP;
            $excludeIPs[] = $newIP; // 添加到排除列表，避免重复
            logActivity("获取新IP " . ($i + 1) . "/{$ipv4Count}: {$newIP}");
        }

        logActivity("新IPv4地址: " . implode(', ', $newIPv4List));

        // 步骤3: 停止VPS
        logActivity("步骤3: 停止VPS");
        $stopResult = $admin->stop($vps_id);
        
        if (!isset($stopResult['done']) || !$stopResult['done']) {
            $errorMsg = isset($stopResult['error']) ? json_encode($stopResult['error']) : '未知错误';
            return ['success' => false, 'error' => "停止VPS失败: {$errorMsg}"];
        }

        logActivity("VPS已发送停止信号");
        
        logActivity("等待VPS完全停止 ({$stopWaitTime}秒)...");
        sleep($stopWaitTime);
        
        $statusCheck = waitForVPSStatus($admin, $vps_id, 'stopped', 30);
        if ($statusCheck) {
            logActivity("VPS已确认停止");
        } else {
            logActivity("警告: 无法确认VPS停止状态，继续执行");
        }

        // 步骤4: 更换IP
        logActivity("步骤4: 更换IP配置");
        
        // 合并新的IPv4和旧的IPv6
        $allNewIPs = array_merge($newIPv4List, $oldIPv6List);
        logActivity("最终IP列表: " . implode(', ', $allNewIPs));
        
        $post = [
            'vpsid' => $vps_id,
            'ips' => $newIPv4List,
            'ips6' => $oldIPv6List,
            'hostname' => $vps['hostname'] ?? '',
        ];
        
        $manageResult = $admin->managevps($post);
        
        if (!isset($manageResult['done']['done']) || !$manageResult['done']['done']) {
            logActivity("IP更换失败，尝试重启VPS");
            $admin->start($vps_id);
            
            $errorMsg = isset($manageResult['error']) ? json_encode($manageResult['error']) : '未知错误';
            return ['success' => false, 'error' => "IP更换失败: {$errorMsg}"];
        }

        logActivity("IP配置已更新");
        sleep(3);

        // 步骤5: 启动VPS
        logActivity("步骤5: 启动VPS");
        $startResult = $admin->start($vps_id);
        
        if (!isset($startResult['done']) || !$startResult['done']) {
            logActivity("警告: VPS启动信号发送可能失败");
            $errorMsg = isset($startResult['error']) ? json_encode($startResult['error']) : '未知错误';
            logActivity("启动错误: {$errorMsg}");
        } else {
            logActivity("VPS已发送启动信号");
        }
        
        logActivity("等待VPS完全启动 ({$startWaitTime}秒)...");
        sleep($startWaitTime);
        
        $startCheck = waitForVPSStatus($admin, $vps_id, 'running', 60);
        if ($startCheck) {
            logActivity("VPS已确认启动");
        } else {
            logActivity("警告: 无法确认VPS启动状态");
        }

        // 步骤6: 记录到数据库
        logActivity("步骤6: 记录操作日志");
        
        $service = Capsule::table('tblhosting')
            ->join('tblproducts', 'tblproducts.id', '=', 'tblhosting.packageid')
            ->where('tblhosting.id', $hostingId)
            ->select('tblproducts.name as productname')
            ->first();

        $fee = Capsule::table('tblinvoiceitems')
            ->where('invoiceid', $invoiceId)
            ->where('description', 'like', "%VPS ID: {$vps_id}%")
            ->value('amount') ?? 0;

        $oldIPsStr = implode(', ', $oldIPv4List);
        $newIPsStr = implode(', ', $newIPv4List);
        $notesStr = $targetPool ? "从IP池{$targetPool}分配" : '自动分配';
        if (!empty($oldIPv6List)) {
            $notesStr .= " | 保留IPv6: " . implode(', ', $oldIPv6List);
        }

        Capsule::table('mod_vpsipchange_logs')->insert([
            'userid' => $userId,
            'productname' => $service->productname ?? '未知产品',
            'vps_id' => $vps_id,
            'old_ip' => $oldIPsStr,
            'new_ip' => $newIPsStr,
            'change_time' => date('Y-m-d H:i:s'),
            'fee' => $fee,
            'invoiceid' => $invoiceId,
            'notes' => $notesStr
        ]);

        logActivity("╚══ IP更换完成 ═══");
        logActivity("结果: {$oldIPsStr} → {$newIPsStr}");

        return [
            'success' => true,
            'old_ip' => $oldIPsStr,
            'new_ip' => $newIPsStr
        ];

    } catch (Exception $e) {
        logActivity("═══ IP更换异常 ═══");
        logActivity("VPS ID: {$vps_id}");
        logActivity("错误: " . $e->getMessage());
        logActivity("堆栈: " . $e->getTraceAsString());
        
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function waitForVPSStatus($admin, $vpsId, $targetStatus, $maxWait = 30) {
    $waited = 0;
    $checkInterval = 3;
    
    while ($waited < $maxWait) {
        try {
            $vpsInfo = $admin->listvs(1, 1, array('vpsid' => $vpsId));
            
            if (isset($vpsInfo[$vpsId])) {
                $status = $vpsInfo[$vpsId]['status'] ?? '';
                
                $isRunning = in_array($status, ['running', 'online', '1']);
                $isStopped = in_array($status, ['stopped', 'offline', '0', 'halted']);
                
                if (($targetStatus == 'running' && $isRunning) || 
                    ($targetStatus == 'stopped' && $isStopped)) {
                    return true;
                }
            }
            
            sleep($checkInterval);
            $waited += $checkInterval;
            
        } catch (Exception $e) {
            logActivity("状态检查失败: " . $e->getMessage());
            return false;
        }
    }
    
    return false;
}

/**
 * 获取可用IP - 使用正确的SDK方法
 * @param $admin Virtualizor管理对象
 * @param $ipPoolId IP池ID
 * @param $excludeIPs 要排除的IP数组
 */
function getAvailableIP_Fixed($admin, $ipPoolId, $excludeIPs) {
    if (!is_array($excludeIPs)) {
        $excludeIPs = [$excludeIPs];
    }
    
    try {
        $post = array();
        if (!empty($ipPoolId)) {
            $post['ippid'] = $ipPoolId;
        }
        
        // 使用SDK的ips()方法
        $ipsData = $admin->ips(1, 5000, $post);
        
        if (!isset($ipsData['ips']) || empty($ipsData['ips'])) {
            return null;
        }

        foreach ($ipsData['ips'] as $ipInfo) {
            if (!filter_var($ipInfo['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                continue;
            }
            
            $vpsid = isset($ipInfo['vpsid']) ? $ipInfo['vpsid'] : 0;
            $locked = isset($ipInfo['locked']) ? $ipInfo['locked'] : 0;
            
            if ($vpsid == 0 && $locked == 0) {
                if (!in_array($ipInfo['ip'], $excludeIPs)) {
                    return $ipInfo['ip'];
                }
            }
        }

        return null;

    } catch (Exception $e) {
        logActivity("获取可用IP失败: " . $e->getMessage());
        return null;
    }
}

/**
 * 从指定IP池获取可用IP - 使用正确的SDK方法
 * @param $admin Virtualizor管理对象
 * @param $poolId IP池ID
 * @param $excludeIPs 要排除的IP数组
 */
function getAvailableIPFromPool_Fixed($admin, $poolId, $excludeIPs) {
    if (!is_array($excludeIPs)) {
        $excludeIPs = [$excludeIPs];
    }
    
    try {
        $post = array('ippid' => $poolId);
        
        // 使用SDK的ips()方法
        $ipsData = $admin->ips(1, 5000, $post);
        
        if (!isset($ipsData['ips'])) {
            return null;
        }
        
        foreach ($ipsData['ips'] as $ipInfo) {
            if (!filter_var($ipInfo['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                continue;
            }
            
            $vpsid = isset($ipInfo['vpsid']) ? $ipInfo['vpsid'] : 0;
            $locked = isset($ipInfo['locked']) ? $ipInfo['locked'] : 0;
            
            if ($vpsid == 0 && $locked == 0) {
                if (!in_array($ipInfo['ip'], $excludeIPs)) {
                    return $ipInfo['ip'];
                }
            }
        }
        
        return null;
    } catch (Exception $e) {
        logActivity("从IP池{$poolId}获取可用IP失败: " . $e->getMessage());
        return null;
    }
}

function getAddonModuleParams($moduleName) {
    $params = [];
    $moduleConfig = Capsule::table('tbladdonmodules')->where('module', $moduleName)->get();
    foreach ($moduleConfig as $conf) {
        $params[$conf->setting] = $conf->value;
    }
    return $params;
}
