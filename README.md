# WP Performance Analyser

A comprehensive WordPress plugin for monitoring and analyzing plugin execution times and database query performance.

## Features

- **Plugin Execution Time Tracking**: Monitor how long each active plugin takes to load
- **Database Query Analysis**: Track query execution times, types, and frequencies
- **Real-time Performance Dashboard**: View current performance metrics at a glance
- **Historical Data**: Store and analyze performance trends over time
- **Performance Reports**: Export data in CSV format for further analysis
- **Customizable Settings**: Configure data retention, sampling rates, and tracking options

## Installation

1. Upload the `wp-performance-analyser` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to the 'Performance' menu in your WordPress admin

## Configuration

### Enable Query Tracking

For detailed query analysis, add the following to your `wp-config.php`:

```php
define('SAVEQUERIES', true);
```

### Settings

Navigate to Performance > Settings to configure:

- **Enable Tracking**: Toggle performance monitoring on/off
- **Data Retention**: Set how long to keep performance logs (1-365 days)
- **Sample Rate**: Configure what percentage of requests to track (1-100%)

## Usage

### Main Dashboard

The main dashboard provides an overview of:
- Current page load time
- Number of active plugins
- Total database queries
- Query execution time
- Memory usage
- Top 5 slowest plugins

### Plugin Performance

View detailed metrics for each plugin:
- Average execution time
- Maximum/minimum execution times
- Number of samples collected
- Detailed performance history

### Query Analysis

Analyze database queries by:
- Query type (SELECT, INSERT, UPDATE, DELETE)
- Execution time
- Calling plugin or function
- Query frequency

## Performance Metrics

The plugin tracks:

- **Execution Time**: Time taken for each plugin to load
- **Memory Usage**: Peak memory consumption
- **Query Count**: Number of database queries per request
- **Query Time**: Total time spent on database operations
- **Page Load Time**: Overall page generation time

## Data Export

Export performance data in CSV format for:
- External analysis
- Performance reporting
- Historical comparisons

## Requirements

- WordPress 5.0 or higher
- PHP 7.0 or higher
- MySQL 5.6 or higher

## Performance Impact

The plugin is designed to have minimal impact on site performance:
- Lightweight tracking mechanisms
- Configurable sampling rates
- Automatic cleanup of old data
- Optimized database queries

## Troubleshooting

### No Query Data Showing

Ensure `SAVEQUERIES` is defined in `wp-config.php`:
```php
define('SAVEQUERIES', true);
```

### High Memory Usage

Adjust the sampling rate in Settings to reduce the tracking frequency.

### Missing Plugin Data

Some plugins may not be trackable if they:
- Load before this plugin
- Use non-standard loading mechanisms
- Are must-use plugins

## Developer Notes

### Database Table

The plugin creates a table `wp_wppa_performance_logs` with the following structure:
- `id`: Primary key
- `page_url`: URL of the tracked page
- `plugin_name`: Name of the plugin
- `execution_time`: Time in seconds
- `memory_usage`: Memory in bytes
- `query_count`: Number of queries
- `query_time`: Total query time
- `timestamp`: When the data was recorded

### Hooks and Filters

The plugin provides several hooks for developers:
- `wppa_before_tracking`: Before performance tracking starts
- `wppa_after_tracking`: After performance tracking completes
- `wppa_performance_data`: Filter performance data before saving

## Author

**Andreas Beder**  
- Email: andreas@moving-bytes.at  
- GitHub: [https://github.com/andreas83/wp-performance-analyser](https://github.com/andreas83/wp-performance-analyser)

## Credits

Development assisted by Claude AI Assistant (Anthropic)

## License

GPL v2 or later

## Support

For issues, feature requests, or contributions, please visit the [GitHub repository](https://github.com/andreas83/wp-performance-analyser).