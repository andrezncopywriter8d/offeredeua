<?php
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/clmns.php';

class Tabulator
{
    public static function get_stats_columns(array $columns, ?string $groupByClmnTitle = null, array $groupByFields = []): string
    {
        $columnSettings = TableColumns::$statsClmns;
        $tabulatorColumns = [];

        // Prepend the group column titled "Groups" — it holds the tree hierarchy
        if (!empty($groupByFields)) {
            $groupCol = $columnSettings['group'];
            $groupCol['title'] = 'Groups';
            $tabulatorColumns[] = $groupCol;
        }

        for ($i = 0; $i < count($columns); $i++) {
            $field = $columns[$i]['field'];
            // Skip the group column (already prepended) and groupby dimension columns
            if ($field === 'group' || in_array($field, $groupByFields))
                continue;
            $width = $columns[$i]['width'] ?? -1;
            if (array_key_exists($field, $columnSettings)) {
                $tabulatorColumns[] = $columnSettings[$field];
            } elseif (str_starts_with($field, 'event.')) {
                $title = $columns[$i]['title'] ?? ucwords(str_replace('_', ' ', substr($field, 6)));
                $tabulatorColumns[] = [
                    'title' => $title,
                    'field' => $field,
                    'sorter' => 'number',
                    'hozAlign' => 'right',
                    'bottomCalc' => 'sum',
                ];
            } else {
                $tabulatorColumns[] = ["title" => $field, "field" => $field];
            }
            if ($width === -1)
                continue;
            $tabulatorColumns[count($tabulatorColumns) - 1]["width"] = $width;
        }

        $clmnsJson = json_encode($tabulatorColumns, JSON_UNESCAPED_SLASHES);
        $clmnsJson = str_replace('"FSTART', '', $clmnsJson);
        $clmnsJson = str_replace('FEND"', '', $clmnsJson);
        return $clmnsJson;
    }


    public static function get_clicks_columns(?int $campId, string $timezone, array $columns): string
    {
        $columnSettings = TableColumns::$clickClmns;

        $defaultColumns =
        [
            "userid" => [
                "title" => "UserID",
                "field" => "userid",
                "headerTooltip" => "Persistent user identifier",
                "headerSort" => false,
                "editor" => false,
            ],
            "clickid" => [
                "title" => "ClickID",
                "field" => "clickid",
                "headerTooltip" => "Click identifier for full funnel pass",
                "headerSort" => false,
                "editor" => false,
            ],
            "time" => [
                "title" => "Time",
                "field" => "time",
                "formatter" => "datetime",
                "formatterParams" => [
                        "inputFormat" => "unix",
                        "outputFormat" => "yyyy-MM-dd HH:mm:ss",
                        "timezone" => "$timezone"
                    ],
                "headerTooltip" => "Date and time according to selected timezone",
                "sorter" => "datetime",
                "sorterParams" => [
                        "format" => "unix"
                ],
                "editor" => false
            ]
        ];

        $tabulatorColumns = [];
        for ($i = 0; $i < count($columns); $i++) {
            $clmn = $columns[$i]['field'];
            $width = $columns[$i]['width'] ?? -1;
            if (array_key_exists($clmn, $columnSettings)) {
                $tabulatorColumns[] = $columnSettings[$clmn];
            } else if (array_key_exists($clmn, $defaultColumns)) {
                $tabulatorColumns[] = $defaultColumns[$clmn];
            } elseif (str_starts_with($clmn, 'param.')) {
                $paramKey = substr($clmn, 6);
                $tabulatorColumns[] = [
                    "title" => "$paramKey\u{1F310}",
                    "field" => "param.$paramKey",
                    "editor" => false,
                    "headerFilter" => false,
                    "headerTooltip" => "URL parameter: $paramKey",
                ];
            } else {
                $tabulatorColumns[] = ["title" => $clmn, "field" => $clmn];
            }
            if ($width === -1)
                continue;
            $tabulatorColumns[count($tabulatorColumns) - 1]["width"] = $width;
        }
        $clmnsJson = json_encode($tabulatorColumns, JSON_UNESCAPED_SLASHES);
        $clmnsJson = str_replace('"FSTART', '', $clmnsJson);
        $clmnsJson = str_replace('FEND"', '', $clmnsJson);
        return $clmnsJson;
    }

    public static function get_campaigns_columns(array $columns): string
    {
        $nameWidth = 90;
        foreach ($columns as $col) {
            if (($col['field'] ?? '') === 'name' && isset($col['width']) && $col['width'] > 0)
                $nameWidth = $col['width'];
        }

        $defaultClmns = <<<JSON
    [
        {
            "title": "ID",
            "field": "id",
            "visible": false,
        },
        {
            "title": "Name",
            "formatter": function(cell) {
                const data = cell.getRow().getData();
                const id = data.id;
                const name = data.name;
                if (!id) return name || '';
                return `<div class="camp-name-cell">
                    <a href="campsettings.php?campId=\${id}" class="camp-name-link">\${name}</a>
                    <button class="camp-menu-btn" title="Actions"><i class="bi bi-three-dots-vertical"></i></button>
                </div>`;
            },
            "field": "name",
            "headerFilter": false,
            "width": $nameWidth,
            "editor":false,
            "cellClick": campNameCellClick,
            "bottomCalc":() => "TOTAL"
        },
JSON;

        $filteredColumns = array_values(array_filter($columns, fn($c) => !in_array($c['field'] ?? '', ['name', 'actions'])));
        $statColumns = Tabulator::get_stats_columns($filteredColumns);
        $defaultClmns .= substr($statColumns, 1);
        return $defaultClmns;
    }
}
