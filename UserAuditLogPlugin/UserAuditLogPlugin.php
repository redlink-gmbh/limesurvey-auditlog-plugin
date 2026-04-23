<?php

class UserAuditLogPlugin extends PluginBase
{
    protected $storage = 'DbStorage';

    static protected $name        = 'UserAuditLogPlugin';
    static protected $description = 'Records a complete audit trail of user interactions with surveys for eCRF and GDPR compliance.';

    private const QUESTION_TYPES = [
        '5' => 'five_point_choice',
        'A' => 'array_radio_five_point',
        'B' => 'array_radio_ten_point',
        'C' => 'array_radio_yes_no_uncertain',
        'D' => 'date_time',
        'E' => 'array_radio_increase_same_decrease',
        'F' => 'array_radio',
        'G' => 'gender',
        'H' => 'array_radio_by_column',
        'I' => 'language_switch',
        'K' => 'multiple_numerical',
        'L' => 'list_radio',
        'M' => 'multiple_choice',
        'N' => 'numerical',
        'O' => 'list_with_comment',
        'P' => 'multiple_choice_with_comments',
        'Q' => 'multiple_short_text',
        'R' => 'ranking',
        'S' => 'short_text',
        'T' => 'long_text',
        'U' => 'huge_text',
        'X' => 'boilerplate',
        'Y' => 'yes_no',
        '!' => 'list_dropdown',
        ':' => 'array_numbers',
        ';' => 'array_text',
        '|' => 'file_upload',
        '*' => 'equation',
    ];

    public function init(): void
    {
        $this->subscribe('beforeSurveyPage');
        $this->subscribe('afterSurveyComplete');
        $this->subscribe('newDirectRequest');
        $this->subscribe('beforeSurveySettings');
        $this->subscribe('newSurveySettings');

        $this->ensureTable();
        $this->ensureColumns();
    }

    public function beforeSurveySettings(): void
    {
        $oEvent   = $this->event;
        $surveyId = (int) $oEvent->get('survey');
        $current  = $this->get('active', 'Survey', $surveyId) ?? '0';

        $oEvent->set("surveysettings.{$this->id}", [
            'name'     => get_class($this),
            'settings' => [
                'active' => [
                    'type'    => 'select',
                    'label'   => 'Audit log for this survey:',
                    'options' => ['0' => 'Deactivated', '1' => 'Activated'],
                    'default' => '0',
                    'current' => $current,
                    'help'    => 'When activated, all user interactions are logged to the audit table.',
                ],
            ],
        ]);
    }

    public function newSurveySettings(): void
    {
        $event    = $this->event;
        $surveyId = (int) $event->get('survey');

        foreach ((array) $event->get('settings') as $name => $value) {
            $this->set($name, $value, 'Survey', $surveyId);
        }
    }

    private function isSurveyActive(int $surveyId): bool
    {
        $val = $this->get('active', 'Survey', $surveyId);
        return in_array($val, ['1', 1, true], true);
    }

    public function beforeSurveyPage(): void
    {
        $surveyId = (int) $this->event->get('surveyId');
        if (!$surveyId || !$this->isSurveyActive($surveyId)) {
            return;
        }

        $surveySession = Yii::app()->session['survey_' . $surveyId] ?? [];
        $token         = $surveySession['token'] ?? null;
        $postThisstep = Yii::app()->request->getPost('thisstep', null);
        $postMove     = Yii::app()->request->getPost('move', null);

        $step = $this->event->get('step') ?? null;

        if ($step === null && $postThisstep !== null) {
            $ts = (int) $postThisstep;
            if ($postMove === 'movenext' || $postMove === 'movesubmit') {
                $step = $ts + 1;
            } elseif ($postMove === 'moveprev') {
                $step = $ts - 1;
            } else {
                $step = $ts;
            }
        }
        $eventType     = ($step === null || (int) $step <= 0) ? 'survey_open' : 'page_load';

        $this->writeLog([
            'survey_id'         => $surveyId,
            'participant_token' => $token,
            'event_type'        => $eventType,
            'page_number'       => $step !== null ? (int) $step : null,
        ]);

        $endpointUrl = Yii::app()->createUrl('plugins/direct', [
            'plugin'   => 'UserAuditLogPlugin',
            'function' => 'logAnswerChange',
        ]);
        $stepJs = $step !== null ? (int) $step : 'null';

        Yii::app()->clientScript->registerScript(
            'ualp_change_listener',
            <<<JS
(function () {
    var surveyId   = {$surveyId};
    var pageNumber = {$stepJs};
    var endpoint   = "{$endpointUrl}";

    function resolvePageNumber() {
        if (pageNumber !== null) return pageNumber;
        var el = document.getElementById('thisstep') || document.querySelector('input[name="thisstep"]');
        if (!el) return null;
        var n = parseInt(el.value, 10);
        return isNaN(n) ? null : n;
    }

    function parseName(name) {
        var m = name.match(/^(\d+)X(\d+)X(\d+)(\w*)$/);
        if (!m) return null;
        var subParts = m[4] ? m[4].split('_') : [];
        return { qid: parseInt(m[3], 10), gid: parseInt(m[2], 10), sub: subParts[0] || null, col: subParts[1] || null };
    }

    function getValue(el) {
        if (el.type === 'checkbox' || el.type === 'radio') return el.checked ? el.value : null;
        return el.value !== '' ? el.value : null;
    }

    var oldValues = {};
    \$('input, select, textarea').each(function () {
        if (!this.name || !parseName(this.name)) return;
        if (this.type === 'radio') {
            if (this.checked) {
                oldValues[this.name] = this.value;
            } else if (!(this.name in oldValues)) {
                oldValues[this.name] = null;
            }
        } else {
            oldValues[this.name] = getValue(this);
        }
    });

    function sendChange(el, oldVal, newVal) {
        var parsed = parseName(el.name);
        if (!parsed) return;
        var resolvedPage = resolvePageNumber();
        var changeData = {
            survey_id:         surveyId,
            group_id:          parsed.gid,
            question_id:       parsed.qid,
            sub_question_code: parsed.sub || '',
            col_question_code: parsed.col || '',
            input_type:        el.type || el.tagName.toLowerCase(),
            old_value:         oldVal !== null ? oldVal : '',
            new_value:         newVal !== null ? newVal : ''
        };
        if (resolvedPage !== null) changeData.page_number = resolvedPage;
        var params = new URLSearchParams(changeData);
        var sep = endpoint.indexOf('?') !== -1 ? '&' : '?';
        fetch(endpoint + sep + params.toString(), {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'include',
            cache: 'no-store'
        }).then(function (r) {
            if (!r.ok) console.warn('[UALP] request failed', r.status);
        }).catch(function (err) {
            console.warn('[UALP] error', err);
        });
    }

    \$(document).on('change', 'input, select, textarea', function () {
        var el = this;
        if (!el.name) return;
        if (!parseName(el.name)) return;
        var newVal = getValue(el);
        var oldVal = oldValues[el.name] !== undefined ? oldValues[el.name] : null;
        oldValues[el.name] = newVal;
        sendChange(el, oldVal, newVal);
    });

    \$('input').each(function () {
        if (!this.name || !parseName(this.name)) return;
        var el = this;
        var proto = Object.getPrototypeOf(el);
        var descriptor = Object.getOwnPropertyDescriptor(proto, 'value');
        if (!descriptor || !descriptor.set) return;
        Object.defineProperty(el, 'value', {
            get: function () { return descriptor.get.call(el); },
            set: function (v) {
                descriptor.set.call(el, v);
                var newVal = v !== '' ? v : null;
                var oldVal = oldValues[el.name] !== undefined ? oldValues[el.name] : null;
                if (oldVal === newVal) return;
                oldValues[el.name] = newVal;
                sendChange(el, oldVal, newVal);
            },
            configurable: true
        });
    });

    \$(document).on('click', '[data-limesurvey-submit*="saveall"]', function () {
        var resolvedPage = resolvePageNumber();
        var saveData = { survey_id: surveyId, event_type: 'survey_save' };
        if (resolvedPage !== null) saveData.page_number = resolvedPage;
        var params = new URLSearchParams(saveData);

        var sep = endpoint.indexOf('?') !== -1 ? '&' : '?';
        fetch(endpoint + sep + params.toString(), {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'include',
            cache: 'no-store'
        }).catch(function (err) {
            console.warn('[UALP] survey_save error', err);
        });
    });
})();
JS
        );
    }

    public function afterSurveyComplete(): void
    {
        $surveyId = (int) $this->event->get('surveyId');
        if (!$surveyId || !$this->isSurveyActive($surveyId)) {
            return;
        }

        $surveySession = Yii::app()->session['survey_' . $surveyId] ?? [];
        $token         = $surveySession['token'] ?? null;

        $this->writeLog([
            'survey_id'         => $surveyId,
            'participant_token' => $token,
            'event_type'        => 'survey_submit',
        ]);
    }

    public function newDirectRequest(): void
    {
        if ($this->event->get('function') !== 'logAnswerChange') {
            return;
        }

        $request  = Yii::app()->request;
        $surveyId = (int) $request->getParam('survey_id');

        if (!$surveyId || !$this->isSurveyActive($surveyId)) {
            http_response_code(403);
            die();
        }

        $surveySession = Yii::app()->session['survey_' . $surveyId] ?? [];
        $token         = $surveySession['token'] ?? null;
        $eventType     = $request->getParam('event_type');

        if ($eventType === 'survey_save') {
            $rawPage = $request->getParam('page_number');
            $this->writeLog([
                'survey_id'         => $surveyId,
                'participant_token' => $token,
                'event_type'        => 'survey_save',
                'page_number'       => ($rawPage !== null && $rawPage !== '') ? (int) $rawPage : null,
            ]);
            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'ok']);
            die();
        }

        $qid    = (int) $request->getParam('question_id');
        $subRaw = $request->getParam('sub_question_code');
        $colRaw = $request->getParam('col_question_code');
        $subQid = null;
        $colQid = null;

        if ($subRaw) {
            $sub = Question::model()->find(
                'parent_qid = :p AND title = :t AND scale_id = 0',
                [':p' => $qid, ':t' => $subRaw]
            );
            $subQid = $sub ? (int) $sub->qid : null;
        }

        if ($colRaw) {
            $col = Question::model()->find(
                'parent_qid = :p AND title = :t AND scale_id = 1',
                [':p' => $qid, ':t' => $colRaw]
            );
            $colQid = $col ? (int) $col->qid : null;
        }

        $question  = $qid ? Question::model()->findByPk($qid) : null;
        $inputType = $question
            ? (self::QUESTION_TYPES[$question->type] ?? $question->type)
            : $request->getParam('input_type');

        $rawPage = $request->getParam('page_number');
        $this->writeLog([
            'survey_id'         => $surveyId,
            'participant_token' => $token,
            'event_type'        => 'answer_change',
            'page_number'       => ($rawPage !== null && $rawPage !== '') ? (int) $rawPage : null,
            'group_id'          => (int) $request->getParam('group_id'),
            'question_id'       => $qid ?: null,
            'sub_question_id'   => $subQid,
            'column_id'         => $colQid,
            'input_type'        => $inputType,
            'old_value'         => $request->getParam('old_value') !== '' ? $request->getParam('old_value') : null,
            'new_value'         => $request->getParam('new_value') !== '' ? $request->getParam('new_value') : null,
        ]);

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok']);
        die();
    }

    private function ensureTable(): void
    {
        $db    = Yii::app()->db;
        $table = $db->tablePrefix . 'user_audit_log';

        if ($db->schema->getTable($table, true) !== null) {
            return;
        }

        $db->createCommand()->createTable($table, [
            'id'                => 'BIGSERIAL PRIMARY KEY',
            'created_at'        => 'TIMESTAMPTZ NOT NULL DEFAULT NOW()',
            'survey_id'         => 'INTEGER NOT NULL',
            'participant_token' => 'VARCHAR(255)',
            'oauth_user_id'     => 'VARCHAR(255)',
            'oauth_username'    => 'VARCHAR(255)',
            'event_type'        => 'VARCHAR(50) NOT NULL',
            'page_number'       => 'INTEGER',
            'group_id'          => 'INTEGER',
            'question_id'       => 'INTEGER',
            'sub_question_id'   => 'INTEGER',
            'column_id'         => 'INTEGER',
            'input_type'        => 'VARCHAR(50)',
            'old_value'         => 'TEXT',
            'new_value'         => 'TEXT',
            'session_id'        => 'VARCHAR(255)',
            'ip_address'        => 'VARCHAR(45)',
        ]);

        foreach (['survey_id', 'created_at', 'oauth_user_id', 'participant_token', 'event_type'] as $col) {
            $db->createCommand()->createIndex("idx_ual_{$col}", $table, $col);
        }
    }

    private function ensureColumns(): void
    {
        $db    = Yii::app()->db;
        $table = $db->tablePrefix . 'user_audit_log';

        $schema = $db->schema->getTable($table, true);
        if ($schema === null) {
            return;
        }

        if (!isset($schema->columns['column_id'])) {
            $db->createCommand()->addColumn($table, 'column_id', 'INTEGER');
        }
    }

    private function writeLog(array $data): void
    {
        $yiiUser = Yii::app()->user;
        $session = Yii::app()->session;

        try {
            Yii::app()->db->createCommand()->insert(
                Yii::app()->db->tablePrefix . 'user_audit_log',
                [
                    'survey_id'         => $data['survey_id'],
                    'participant_token' => $data['participant_token'] ?? null,
                    'oauth_user_id'     => $yiiUser->isGuest ? null : $yiiUser->id,
                    'oauth_username'    => $yiiUser->isGuest ? null : $yiiUser->name,
                    'event_type'        => $data['event_type'],
                    'page_number'       => $data['page_number'] ?? null,
                    'group_id'          => $data['group_id'] ?? null,
                    'question_id'       => $data['question_id'] ?? null,
                    'sub_question_id'   => $data['sub_question_id'] ?? null,
                    'column_id'         => $data['column_id'] ?? null,
                    'input_type'        => $data['input_type'] ?? null,
                    'old_value'         => $data['old_value'] ?? null,
                    'new_value'         => $data['new_value'] ?? null,
                    'session_id'        => $session->sessionID,
                    'ip_address'        => $_SERVER['REMOTE_ADDR'] ?? null,
                ]
            );
        } catch (Exception $e) {
            error_log('[UALP] writeLog ERROR: ' . $e->getMessage());
        }
    }
}
