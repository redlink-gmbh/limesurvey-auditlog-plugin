<?php

class UserAuditLogPlugin extends PluginBase
{
    protected $storage = 'DbStorage';

    static protected $name        = 'UserAuditLogPlugin';
    static protected $description = 'Records a complete audit trail of user interactions with surveys for eCRF and GDPR compliance.';

    public function init(): void
    {
        $this->subscribe('beforeSurveyPage');
        $this->subscribe('afterSurveyComplete');
        $this->subscribe('newDirectRequest');
        $this->subscribe('beforeSurveySettings');
        $this->subscribe('newSurveySettings');

        $this->ensureTable();
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
        $step          = $this->event->get('step')
                      ?? Yii::app()->request->getPost('thisstep', null)
                      ?? $surveySession['groupseq']
                      ?? $surveySession['step']
                      ?? null;
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

    function parseName(name) {
        var m = name.match(/^(\d+)X(\d+)X(\d+)(\w*)$/);
        if (!m) return null;
        return { qid: parseInt(m[3], 10), gid: parseInt(m[2], 10), sub: m[4] || null };
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

    \$(document).on('change', 'input, select, textarea', function () {
        var el = this;
        if (!el.name) return;
        var parsed = parseName(el.name);
        if (!parsed) return;

        var newVal = getValue(el);
        var oldVal = oldValues[el.name] !== undefined ? oldValues[el.name] : null;
        oldValues[el.name] = newVal;

        var params = new URLSearchParams({
            survey_id:         surveyId,
            page_number:       pageNumber !== null ? pageNumber : 0,
            group_id:          parsed.gid,
            question_id:       parsed.qid,
            sub_question_code: parsed.sub || '',
            input_type:        el.type || el.tagName.toLowerCase(),
            old_value:         oldVal !== null ? oldVal : '',
            new_value:         newVal !== null ? newVal : ''
        });

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
    });

    \$(document).on('click', '[data-limesurvey-submit*="saveall"]', function () {
        var params = new URLSearchParams({
            survey_id:   surveyId,
            page_number: pageNumber !== null ? pageNumber : 0,
            event_type:  'survey_save'
        });

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
            $this->writeLog([
                'survey_id'         => $surveyId,
                'participant_token' => $token,
                'event_type'        => 'survey_save',
                'page_number'       => (int) $request->getParam('page_number'),
            ]);
            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'ok']);
            die();
        }

        $qid    = (int) $request->getParam('question_id');
        $subRaw = $request->getParam('sub_question_code');
        $subQid = null;

        if ($subRaw) {
            $sub = Question::model()->find(
                'parent_qid = :p AND title = :t',
                [':p' => $qid, ':t' => $subRaw]
            );
            $subQid = $sub ? (int) $sub->qid : null;
        }

        $this->writeLog([
            'survey_id'         => $surveyId,
            'participant_token' => $token,
            'event_type'        => 'answer_change',
            'page_number'       => (int) $request->getParam('page_number'),
            'group_id'          => (int) $request->getParam('group_id'),
            'question_id'       => $qid ?: null,
            'sub_question_id'   => $subQid,
            'input_type'        => $request->getParam('input_type'),
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
