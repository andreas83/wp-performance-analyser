(function($) {
    'use strict';

    $(document).ready(function() {
        initPerformanceCharts();
        initRealTimeMonitoring();
        initFilterHandlers();
        initTooltips();
    });

    function initPerformanceCharts() {
        var chartCanvas = document.getElementById('wppa-query-chart');
        if (!chartCanvas) return;

        var ctx = chartCanvas.getContext('2d');
        
        fetchPerformanceData('query-stats').then(function(data) {
            drawQueryChart(ctx, data);
        });
    }

    function drawQueryChart(ctx, data) {
        var canvas = ctx.canvas;
        var width = canvas.width = canvas.offsetWidth;
        var height = canvas.height = 300;
        
        ctx.clearRect(0, 0, width, height);
        
        if (!data || !data.length) {
            ctx.fillStyle = '#666';
            ctx.font = '14px sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('No query data available', width / 2, height / 2);
            return;
        }
        
        var maxValue = Math.max(...data.map(d => d.value));
        var barWidth = width / data.length - 10;
        var scale = (height - 40) / maxValue;
        
        ctx.fillStyle = '#0073aa';
        ctx.strokeStyle = '#005177';
        
        data.forEach(function(item, index) {
            var x = index * (barWidth + 10) + 5;
            var barHeight = item.value * scale;
            var y = height - barHeight - 20;
            
            ctx.fillRect(x, y, barWidth, barHeight);
            
            ctx.fillStyle = '#333';
            ctx.font = '12px sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText(item.label, x + barWidth / 2, height - 5);
            
            ctx.fillText(item.value, x + barWidth / 2, y - 5);
            ctx.fillStyle = '#0073aa';
        });
    }

    function initRealTimeMonitoring() {
        if (!$('.wppa-realtime-monitor').length) return;
        
        setInterval(function() {
            updateRealTimeStats();
        }, 5000);
    }

    function updateRealTimeStats() {
        $.ajax({
            url: wppa_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wppa_get_realtime_stats',
                nonce: wppa_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    updateDashboard(response.data);
                }
            }
        });
    }

    function updateDashboard(data) {
        if (data.page_load_time) {
            $('.wppa-page-load-time').text(data.page_load_time + ' ms');
        }
        if (data.query_count) {
            $('.wppa-query-count').text(data.query_count);
        }
        if (data.memory_usage) {
            $('.wppa-memory-usage').text(data.memory_usage);
        }
    }

    function initFilterHandlers() {
        $('#wppa-date-filter').on('change', function() {
            var range = $(this).val();
            reloadDataWithFilter('date_range', range);
        });

        $('#wppa-plugin-filter').on('change', function() {
            var plugin = $(this).val();
            reloadDataWithFilter('plugin', plugin);
        });

        $('.wppa-refresh-btn').on('click', function(e) {
            e.preventDefault();
            location.reload();
        });
    }

    function reloadDataWithFilter(filterType, filterValue) {
        var currentUrl = window.location.href.split('?')[0];
        var params = new URLSearchParams(window.location.search);
        params.set(filterType, filterValue);
        window.location.href = currentUrl + '?' + params.toString();
    }

    function fetchPerformanceData(dataType) {
        return new Promise(function(resolve, reject) {
            $.ajax({
                url: wppa_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'wppa_get_performance_data',
                    data_type: dataType,
                    nonce: wppa_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        resolve(response.data);
                    } else {
                        reject(response.message);
                    }
                },
                error: function() {
                    reject('Failed to fetch performance data');
                }
            });
        });
    }

    function initTooltips() {
        $('.wppa-help-icon').on('mouseenter', function() {
            var tooltip = $(this).data('tooltip');
            if (tooltip) {
                var $tooltip = $('<div class="wppa-tooltip-popup">' + tooltip + '</div>');
                $('body').append($tooltip);
                
                var offset = $(this).offset();
                $tooltip.css({
                    top: offset.top - $tooltip.outerHeight() - 10,
                    left: offset.left - ($tooltip.outerWidth() / 2) + ($(this).outerWidth() / 2)
                });
            }
        }).on('mouseleave', function() {
            $('.wppa-tooltip-popup').remove();
        });
    }

    window.wppaExportData = function(format) {
        var url = wppa_ajax.ajax_url + '?action=wppa_export_data&format=' + format + '&nonce=' + wppa_ajax.nonce;
        window.location.href = url;
    };

    window.wppaToggleDetails = function(element) {
        var $row = $(element).closest('tr');
        var $details = $row.next('.wppa-details-row');
        
        if ($details.length) {
            $details.toggle();
        } else {
            loadPluginDetails($row);
        }
    };

    function loadPluginDetails($row) {
        var plugin = $row.data('plugin');
        
        $.ajax({
            url: wppa_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wppa_get_plugin_details',
                plugin: plugin,
                nonce: wppa_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    var detailsHtml = '<tr class="wppa-details-row"><td colspan="6">' + response.data + '</td></tr>';
                    $row.after(detailsHtml);
                }
            }
        });
    }

    $(document).on('click', '.wppa-clear-cache', function(e) {
        e.preventDefault();
        
        if (!confirm('Are you sure you want to clear the performance cache?')) {
            return;
        }
        
        $.ajax({
            url: wppa_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wppa_clear_cache',
                nonce: wppa_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert('Cache cleared successfully');
                    location.reload();
                }
            }
        });
    });

})(jQuery);

jQuery(document).ready(function($) {
    $.post(wppa_ajax.ajax_url, {
        action: 'wppa_get_performance_data',
        data_type: 'query-stats',
        nonce: wppa_ajax.nonce
    }, function(response) {
        if (response.success && response.data) {
            var mockData = [
                { label: 'SELECT', value: response.data.select || 45 },
                { label: 'INSERT', value: response.data.insert || 12 },
                { label: 'UPDATE', value: response.data.update || 8 },
                { label: 'DELETE', value: response.data.delete || 3 }
            ];
            
            var ctx = document.getElementById('wppa-query-chart');
            if (ctx) {
                drawQueryChart(ctx.getContext('2d'), mockData);
            }
        }
    });
});

function drawQueryChart(ctx, data) {
    var canvas = ctx.canvas;
    var width = canvas.width = canvas.offsetWidth;
    var height = canvas.height = 300;
    
    ctx.clearRect(0, 0, width, height);
    
    if (!data || !data.length) {
        ctx.fillStyle = '#666';
        ctx.font = '14px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('No query data available', width / 2, height / 2);
        return;
    }
    
    var maxValue = Math.max(...data.map(d => d.value));
    var barWidth = (width / data.length) - 20;
    var scale = (height - 60) / maxValue;
    
    data.forEach(function(item, index) {
        var x = index * (barWidth + 20) + 10;
        var barHeight = item.value * scale;
        var y = height - barHeight - 30;
        
        ctx.fillStyle = '#0073aa';
        ctx.fillRect(x, y, barWidth, barHeight);
        
        ctx.fillStyle = '#333';
        ctx.font = '12px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText(item.label, x + barWidth / 2, height - 10);
        
        ctx.fillText(item.value, x + barWidth / 2, y - 5);
    });
}