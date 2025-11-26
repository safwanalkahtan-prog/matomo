<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\BotTracking\RecordBuilders;

use Piwik\ArchiveProcessor;
use Piwik\ArchiveProcessor\Record;
use Piwik\ArchiveProcessor\RecordBuilder;
use Piwik\Common;
use Piwik\Config\GeneralConfig;
use Piwik\DataAccess\LogAggregator;
use Piwik\DataTable;
use Piwik\Db;
use Piwik\Plugins\Actions\ArchivingHelper;
use Piwik\Plugins\BotTracking\Archiver;
use Piwik\Plugins\BotTracking\BotDetector;
use Piwik\Plugins\BotTracking\Dao\BotRequestsDao;
use Piwik\Plugins\BotTracking\Metrics;
use Piwik\Tracker\Action;
use Piwik\Tracker\PageUrl;

class AIAssistantReports extends RecordBuilder
{
    /**
     * @var array<string, string>
     */
    private const ASSISTANT_MAPPING = [
        'ChatGPT-User'         => 'ChatGPT',
        'MistralAI-User'       => 'Le Chat',
        'Gemini-Deep-Research' => 'Gemini',
        'Claude-User'          => 'Claude',
        'Perplexity-User'      => 'Perplexity',
        'Google-NotebookLM'    => 'NotebookLM',
        'Devin'                => '',
    ];

    public function __construct()
    {
        parent::__construct();

        $this->columnToSortByBeforeTruncation = Metrics::COLUMN_REQUESTS;
        $this->maxRowsInTable                 = (int)GeneralConfig::getConfigValue('datatable_archiving_maximum_rows_bots');
        $this->maxRowsInSubtable              = (int)GeneralConfig::getConfigValue('datatable_archiving_maximum_rows_subtable_bots');
    }

    public function getRecordMetadata(ArchiveProcessor $archiveProcessor): array
    {
        return [
            Record::make(Record::TYPE_BLOB, Archiver::AI_ASSISTANTS_PAGES_RECORD),
            Record::make(Record::TYPE_BLOB, Archiver::AI_ASSISTANTS_REQUESTED_DOCUMENTS_RECORD),
            Record::make(Record::TYPE_BLOB, Archiver::AI_ASSISTANTS_REQUESTED_PAGES_RECORD),
            Record::make(Record::TYPE_BLOB, Archiver::AI_ASSISTANTS_DOCUMENTS_RECORD),
            Record::make(Record::TYPE_NUMERIC, Metrics::METRIC_AI_ASSISTANTS_UNIQUE_ASSISTANTS)
                ->setIsCountOfBlobRecordRows(Archiver::AI_ASSISTANTS_PAGES_RECORD),
            Record::make(Record::TYPE_NUMERIC, Metrics::METRIC_AI_ASSISTANTS_REQUESTS),
            Record::make(Record::TYPE_NUMERIC, Metrics::METRIC_AI_ASSISTANTS_ACQUIRED_VISITS),
            Record::make(Record::TYPE_NUMERIC, Metrics::METRIC_AI_ASSISTANTS_UNIQUE_PAGE_URLS)
                ->setIsCountOfBlobRecordLeafRows(Archiver::AI_ASSISTANTS_REQUESTED_PAGES_RECORD),
            Record::make(Record::TYPE_NUMERIC, Metrics::METRIC_AI_ASSISTANTS_UNIQUE_DOCUMENT_URLS)
                ->setIsCountOfBlobRecordLeafRows(Archiver::AI_ASSISTANTS_REQUESTED_DOCUMENTS_RECORD),
            Record::make(Record::TYPE_NUMERIC, Metrics::METRIC_AI_ASSISTANTS_NOT_FOUND_REQUESTS),
            Record::make(Record::TYPE_NUMERIC, Metrics::METRIC_AI_ASSISTANTS_SERVER_ERROR_REQUESTS),
        ];
    }

    public function isEnabled(ArchiveProcessor $archiveProcessor): bool
    {
        // don't process reports for any segment
        return $archiveProcessor->getParams()->getSegment()->isEmpty();
    }

    protected function aggregate(ArchiveProcessor $archiveProcessor): array
    {
        $tables = [
            Archiver::AI_ASSISTANTS_PAGES_RECORD               => new DataTable(),
            Archiver::AI_ASSISTANTS_DOCUMENTS_RECORD           => new DataTable(),
            Archiver::AI_ASSISTANTS_REQUESTED_PAGES_RECORD     => new DataTable(),
            Archiver::AI_ASSISTANTS_REQUESTED_DOCUMENTS_RECORD => new DataTable(),
        ];

        $this->populateTables($archiveProcessor, $tables);
        $this->populateNumerics($archiveProcessor, $tables);

        return $tables;
    }

    /**
     * @param array<string, DataTable> $tables
     */
    private function populateTables(ArchiveProcessor $archiveProcessor, array &$tables): void
    {
        $logAggregator = $archiveProcessor->getLogAggregator();
        $visits = $this->queryAcquiredVisitsByAIAssistant($logAggregator);

        $this->populateAssistantTableForActionType($tables, Action::TYPE_PAGE_URL, $logAggregator, $visits);
        $this->populateAssistantTableForActionType($tables, Action::TYPE_DOWNLOAD, $logAggregator, $visits);

        $this->populateRequestTableForActionType($tables[Archiver::AI_ASSISTANTS_REQUESTED_PAGES_RECORD], Action::TYPE_PAGE_URL, $logAggregator);
        $this->populateRequestTableForActionType($tables[Archiver::AI_ASSISTANTS_REQUESTED_DOCUMENTS_RECORD], Action::TYPE_DOWNLOAD, $logAggregator);
    }

    /**
     * @return array<string,int>
     */
    private function queryAcquiredVisitsByAIAssistant(LogAggregator $logAggregator): array
    {
        $where    = $logAggregator->getWhereStatement('log_visit', 'visit_last_action_time');
        $bindBase = $logAggregator->getGeneralQueryBindParams();

        $sql = sprintf(
            "SELECT `referer_name`, COUNT(*) AS `visits`
             FROM %s AS `log_visit`
             WHERE `referer_type` = %d
               AND `referer_name` <> ''
               AND %s
             GROUP BY `referer_name`",
            Common::prefixTable('log_visit'),
            Common::REFERRER_TYPE_AI_ASSISTANT,
            $where
        );

        $stmt   = Db::query($sql, $bindBase);
        $result = [];

        while ($row = $stmt->fetch()) {
            /**
             * @var array{visits: string|int, referer_name: string} $row
             */
            if (in_array($row['referer_name'], self::ASSISTANT_MAPPING)) {
                $key          = (string)array_search($row['referer_name'], self::ASSISTANT_MAPPING);
                $result[$key] = (int)$row['visits'];
            }
        }

        return $result;
    }

    /**
     * @param array<string, DataTable> $tables
     * @param array<string, int> $visits
     * @return void
     */
    private function populateAssistantTableForActionType(array $tables, int $actionType, LogAggregator $logAggregator, array $visits): void
    {
        $where    = $logAggregator->getWhereStatement('bot', 'server_time');
        $bindBase = $logAggregator->getGeneralQueryBindParams();

        $sql = sprintf(
            "SELECT * FROM (SELECT bot.bot_name, log_action.name AS url, COUNT(*) AS requests
             FROM %s AS bot
             INNER JOIN %s AS log_action ON log_action.idaction = bot.idaction_url
             WHERE log_action.name IS NOT NULL
               AND log_action.name <> ''
               AND log_action.type = %d
               AND bot.bot_type = ?
               AND %s
             GROUP BY bot.bot_name, url WITH ROLLUP) AS rollupQuery
             ORDER BY bot_name, requests DESC, url",
            BotRequestsDao::getPrefixedTableName(),
            Common::prefixTable('log_action'),
            $actionType,
            $where
        );

        $resultSet = Db::query($sql, array_merge([BotDetector::BOT_TYPE_AI_ASSISTANT], $bindBase));
        $actionRows = [];

        while ($row = $resultSet->fetch()) {
            /**
             * @var array{requests: int, bot_name: ?string, url: ?string} $row
             */
            $label = $row['bot_name'];
            $url   = $row['url'];

            if (is_null($label)) {
                continue;
            }

            if (!is_null($url)) {
                $actionRows[] = $row;
                continue;
            }

            $metrics = [
                Metrics::COLUMN_REQUESTS          => $row['requests'],
                Metrics::COLUMN_DOCUMENT_REQUESTS => $actionType === Action::TYPE_DOWNLOAD ? $row['requests'] : 0,
                Metrics::COLUMN_PAGE_REQUESTS     => $actionType === Action::TYPE_PAGE_URL ? $row['requests'] : 0,
                Metrics::COLUMN_ACQUIRED_VISITS   => $visits[$label] ?? 0,
            ];

            // we add all records to both tables, so we in the end have the total count of pages & documents in the main table
            $tables[Archiver::AI_ASSISTANTS_PAGES_RECORD]->sumRowWithLabel($label, $metrics);
            $tables[Archiver::AI_ASSISTANTS_DOCUMENTS_RECORD]->sumRowWithLabel($label, $metrics);
        }

        $table = $tables[Archiver::AI_ASSISTANTS_PAGES_RECORD];

        if ($actionType === Action::TYPE_DOWNLOAD) {
            $table = $tables[Archiver::AI_ASSISTANTS_DOCUMENTS_RECORD];
        }

        // use while / array_shift combination instead of foreach to save memory
        while (is_array($actionRows) && count($actionRows)) {
            /**
             * @var array{requests: int, bot_name: string, url: string} $row
             */
            $row   = array_shift($actionRows);
            $label = $row['bot_name'];
            $url   = $row['url'];

            $tableRow = $table->getRowFromLabel($label);

            if (empty($tableRow)) {
                continue;
            }

            $normalized = PageUrl::normalizeUrl($url);
            $url        = $normalized['url'];

            $tableRow->sumRowWithLabelToSubtable($url, [
                Metrics::COLUMN_REQUESTS => $row['requests'],
            ]);
        }
    }

    public function populateRequestTableForActionType(DataTable $table, int $actionType, LogAggregator $logAggregator): void
    {
        $where    = $logAggregator->getWhereStatement('bot', 'server_time');
        $bindBase = $logAggregator->getGeneralQueryBindParams();

        $sql = sprintf(
            "SELECT log_action.name AS url, log_action.url_prefix, COUNT(*) AS requests
             FROM %s AS bot
             INNER JOIN %s AS log_action ON log_action.idaction = bot.idaction_url
             WHERE log_action.name IS NOT NULL
               AND log_action.name <> ''
               AND log_action.type = %d
               AND bot.bot_type = ?
               AND %s
             GROUP BY log_action.name
             ORDER BY requests DESC, log_action.name",
            BotRequestsDao::getPrefixedTableName(),
            Common::prefixTable('log_action'),
            $actionType,
            $where
        );

        $resultSet = Db::query($sql, array_merge([BotDetector::BOT_TYPE_AI_ASSISTANT], $bindBase));

        while ($row = $resultSet->fetch()) {
            /**
             * @var array{requests: int, url: string, url_prefix: ?int} $row
             */
            $path = ArchivingHelper::getActionExplodedNames($row['url'], $actionType, $row['url_prefix']);
            [$row, $level] = $table->walkPath($path, [Metrics::COLUMN_REQUESTS => 0], $this->maxRowsInSubtable);

            if ($row) {
                $row->setColumn(Metrics::COLUMN_REQUESTS, $row['requests']);
            }
        }
    }

    /**
     * @param array<string, DataTable> $tables
     */
    private function populateNumerics(ArchiveProcessor $archiveProcessor, array &$tables): void
    {
        $logAggregator = $archiveProcessor->getLogAggregator();

        $table      = BotRequestsDao::getPrefixedTableName();
        $visitTable = Common::prefixTable('log_visit');

        $where = $logAggregator->getWhereStatement('bot', 'server_time');

        $sql = <<<SQL
SELECT
    COUNT(*) AS requests,
    SUM(CASE WHEN bot.http_status_code = 404 THEN 1 ELSE 0 END) AS not_found_requests,
    SUM(CASE WHEN bot.http_status_code BETWEEN 500 AND 599 THEN 1 ELSE 0 END) AS server_error_requests
FROM $table AS bot
WHERE bot.bot_type = ? AND $where
SQL;

        $bind = [
            BotDetector::BOT_TYPE_AI_ASSISTANT,
        ];
        $bind = array_merge($bind, $logAggregator->getGeneralQueryBindParams());

        $row = Db::fetchRow($sql, $bind) ?: [];

        $visitBind = [
            Common::REFERRER_TYPE_AI_ASSISTANT,
        ];
        $visitBind = array_merge($visitBind, $logAggregator->getGeneralQueryBindParams());

        $where = $logAggregator->getWhereStatement('log_visit', 'visit_last_action_time');

        $visitsSql = sprintf(
            "SELECT COUNT(*) FROM %s log_visit WHERE referer_type = ? AND $where",
            $visitTable
        );

        $acquiredVisits = (int)Db::fetchOne($visitsSql, $visitBind);

        $tables[Metrics::METRIC_AI_ASSISTANTS_UNIQUE_ASSISTANTS]     = $tables[Archiver::AI_ASSISTANTS_PAGES_RECORD]->getRowsCount();
        $tables[Metrics::METRIC_AI_ASSISTANTS_UNIQUE_PAGE_URLS]      = $tables[Archiver::AI_ASSISTANTS_REQUESTED_PAGES_RECORD]->getLeafRowsCount();
        $tables[Metrics::METRIC_AI_ASSISTANTS_UNIQUE_DOCUMENT_URLS]  = $tables[Archiver::AI_ASSISTANTS_REQUESTED_DOCUMENTS_RECORD]->getLeafRowsCount();
        $tables[Metrics::METRIC_AI_ASSISTANTS_REQUESTS]              = (int)($row['requests'] ?? 0);
        $tables[Metrics::METRIC_AI_ASSISTANTS_ACQUIRED_VISITS]       = $acquiredVisits;
        $tables[Metrics::METRIC_AI_ASSISTANTS_NOT_FOUND_REQUESTS]    = (int)($row['not_found_requests'] ?? 0);
        $tables[Metrics::METRIC_AI_ASSISTANTS_SERVER_ERROR_REQUESTS] = (int)($row['server_error_requests'] ?? 0);
    }
}
