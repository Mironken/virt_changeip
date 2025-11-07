<div class="container">
    <h2>VPS更换IP</h2>
    
    {if $message}
        <div class="alert alert-{if $messageType eq 'error'}danger{else}success{/if}">
            {$message}
        </div>
    {/if}

    <div class="panel panel-default">
        <div class="panel-heading">
            <h3 class="panel-title">选择要更换IP的VPS</h3>
        </div>
        <div class="panel-body">
            {if $services}
                <div class="alert alert-info">
                    <strong>注意事项：</strong>
                    <ul>
                        <li>更换IP将自动停止和启动VPS，整个过程大约需要2-3分钟</li>
                        <li>VPS停止期间服务将暂时不可用</li>
                        <li>每次更换IP费用为：<strong>¥{$changeFee}</strong></li>
                        <li>可以选择要更换到的IP段（IP池）</li>
                        <li>更换完成后请更新您的DNS记录</li>
                    </ul>
                </div>
                
                {if $pools}
                <div class="alert alert-success">
                    <strong>可用IP池：</strong>
                    <table class="table table-condensed" style="margin-top: 10px; background: white;">
                        <thead>
                            <tr>
                                <th>IP池名称</th>
                                <th>池ID</th>
                                <th>可用IP数</th>
                            </tr>
                        </thead>
                        <tbody>
                            {foreach from=$pools item=pool}
                            <tr>
                                <td><strong>{$pool->ippool_name}</strong></td>
                                <td>{$pool->ippid}</td>
                                <td>
                                    {if $pool->available_ips > 50}
                                        <span class="label label-success">{$pool->available_ips} 个</span>
                                    {elseif $pool->available_ips > 10}
                                        <span class="label label-warning">{$pool->available_ips} 个</span>
                                    {else}
                                        <span class="label label-danger">{$pool->available_ips} 个</span>
                                    {/if}
                                </td>
                            </tr>
                            {/foreach}
                        </tbody>
                    </table>
                </div>
                {else}
                <div class="alert alert-warning">
                    暂无可用IP池信息，请联系管理员。
                </div>
                {/if}
                
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>产品名称</th>
                            <th>VPS ID</th>
                            <th>当前IP</th>
                            <th>目标IP池</th>
                            <th>更换费用</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        {foreach from=$services item=service}
                        <tr>
                            <td>{$service.productname}</td>
                            <td><code>{$service.vps_id}</code></td>
                            <td><code>{$service.current_ip}</code></td>
                            <td>
                                <form method="post" id="form_{$service.id}" onsubmit="return confirmChange({$service.id}, '{$service.vps_id}', '{$service.current_ip}', {$service.changefee});">
                                    <input type="hidden" name="action" value="change_ip" />
                                    <input type="hidden" name="service_id" value="{$service.id}" />
                                    {if $pools}
                                    <select name="target_pool" id="target_pool_{$service.id}" class="form-control input-sm" required>
                                        <option value="">选择IP池...</option>
                                        {foreach from=$pools item=pool}
                                        <option value="{$pool->ippid}">
                                            {$pool->ippool_name} (可用:{$pool->available_ips})
                                        </option>
                                        {/foreach}
                                    </select>
                                    {else}
                                    <span class="text-muted">无可用IP池</span>
                                    {/if}
                                </form>
                            </td>
                            <td><strong>¥{$service.changefee}</strong></td>
                            <td>
                                {if $pools}
                                <button type="button" class="btn btn-warning btn-sm" onclick="document.getElementById('form_{$service.id}').submit();">
                                    <i class="fa fa-refresh"></i> 更换IP
                                </button>
                                {else}
                                <button type="button" class="btn btn-warning btn-sm" disabled>
                                    <i class="fa fa-ban"></i> 暂不可用
                                </button>
                                {/if}
                            </td>
                        </tr>
                        {/foreach}
                    </tbody>
                </table>
            {else}
                <div class="alert alert-warning">
                    您当前没有可用的VPS服务。
                </div>
            {/if}
        </div>
    </div>

    {if $userlogs}
    <div class="panel panel-default">
        <div class="panel-heading">
            <h3 class="panel-title">IP更换历史记录</h3>
        </div>
        <div class="panel-body">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>产品名称</th>
                        <th>VPS ID</th>
                        <th>旧IP</th>
                        <th>新IP</th>
                        <th>更换时间</th>
                        <th>费用</th>
                        <th>备注</th>
                    </tr>
                </thead>
                <tbody>
                    {foreach from=$userlogs item=log}
                    <tr>
                        <td>{$log->productname}</td>
                        <td><code>{$log->vps_id}</code></td>
                        <td><code>{$log->old_ip}</code></td>
                        <td><code>{$log->new_ip}</code></td>
                        <td>{$log->change_time}</td>
                        <td>¥{$log->fee}</td>
                        <td><small>{$log->notes}</small></td>
                    </tr>
                    {/foreach}
                </tbody>
            </table>
        </div>
    </div>
    {/if}
</div>

<style>
.container {
    margin-top: 20px;
}
code {
    background-color: #f5f5f5;
    padding: 2px 6px;
    border-radius: 3px;
    font-family: monospace;
    font-size: 12px;
}
.panel-body .table {
    margin-bottom: 0;
}
</style>

<script>
function confirmChange(serviceId, vpsId, currentIp, fee) {
    var targetPool = document.getElementById('target_pool_' + serviceId);
    
    if (!targetPool || !targetPool.value) {
        alert('请先选择目标IP池！');
        return false;
    }
    
    var poolText = targetPool.options[targetPool.selectedIndex].text;
    
    var message = '═══ 确认IP更换 ═══\n\n';
    message += 'VPS ID: ' + vpsId + '\n';
    message += '当前IP: ' + currentIp + '\n';
    message += '目标IP池: ' + poolText + '\n';
    message += '费用: ¥' + fee + '\n\n';
    message += '更换过程：\n';
    message += '1. 停止VPS (约10秒)\n';
    message += '2. 更换IP配置\n';
    message += '3. 启动VPS (约15秒)\n\n';
    message += '整个过程约2-3分钟，期间VPS将无法访问。\n\n';
    message += '确定要继续吗？';
    
    return confirm(message);
}
</script>
