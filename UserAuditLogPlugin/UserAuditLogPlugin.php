<?php

class UserAuditLogPlugin extends PluginBase
{
    protected $storage = 'DbStorage';

    static protected $name        = 'UserAuditLogPlugin';
    static protected $description = 'Records a complete audit trail of user interactions with surveys for eCRF and GDPR compliance.';

    private const QUESTION_TYPES = [
        // single choice
        '5' => 'single-choice/five-point-choice',
        'L' => 'single-choice/list-radio',
        'O' => 'single-choice/list-with-comment',
        '!' => 'single-choice/list-dropdown',
        // multiple choice
        'M' => 'multiple-choice/multiple-choice',
        'P' => 'multiple-choice/multiple-choice-with-comments',
        // text question
        'S' => 'text-question/short-text',
        'T' => 'text-question/long-text',
        'U' => 'text-question/huge-text',
        'Q' => 'text-question/multiple-short-text',
        // mask question
        'D' => 'mask-question/date-time',
        'G' => 'mask-question/gender',
        'I' => 'mask-question/language-switch',
        'K' => 'mask-question/multiple-numerical',
        'N' => 'mask-question/numerical',
        'R' => 'mask-question/ranking',
        'Y' => 'mask-question/yes-no',
        'X' => 'mask-question/boilerplate',
        '*' => 'mask-question/equation',
        '|' => 'mask-question/file-upload',
        // array
        '1' => 'array/dual-scale',
        'A' => 'array/five-point-choice',
        'B' => 'array/ten-point-choice',
        'C' => 'array/yes-no-uncertain',
        'E' => 'array/increase-same-decrease',
        'F' => 'array/array',
        'H' => 'array/by-column',
        ':' => 'array/numbers',
        ';' => 'array/text',
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

        $fileUploadQids = array_map(
            fn($q) => (int) $q->qid,
            Question::model()->findAll(
                'sid = :sid AND type = :type AND parent_qid = 0',
                [':sid' => $surveyId, ':type' => '|']
            )
        );
        $fileUploadQidsJs = json_encode($fileUploadQids);

        $dateTimeQids = array_map(
            fn($q) => (int) $q->qid,
            Question::model()->findAll(
                'sid = :sid AND type = :type AND parent_qid = 0',
                [':sid' => $surveyId, ':type' => 'D']
            )
        );
        $dateTimeQidsJs = json_encode($dateTimeQids);

        Yii::app()->clientScript->registerScript(
            'ualp_change_listener',
            <<<JS
(function () {
    // Guard: LimeSurvey re-executes scripts after certain AJAX operations (e.g.
    // file upload). Running a second time would reset oldValues and could add
    // duplicate handlers — bail out immediately if already initialised.
    if (window._ualp_init_{$surveyId}) return;
    window._ualp_init_{$surveyId} = true;

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
        var m = name.match(/^(\d+)X(\d+)X(\d+)(\w*)(?:#(\d+))?$/);
        if (!m) return null;
        var subParts = m[4] ? m[4].split('_') : [];
        return { qid: parseInt(m[3], 10), gid: parseInt(m[2], 10), sub: subParts[0] || null, col: subParts[1] || null, scale: m[5] != null ? parseInt(m[5], 10) : null };
    }

    function getValue(el) {
        if (el.type === 'checkbox' || el.type === 'radio') return (el.checked && el.value !== '') ? el.value : null;
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
        if (parsed.scale !== null) changeData.dual_scale = parsed.scale;
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

    \$(document).off('change.ualp').on('change.ualp', 'input, select, textarea', function () {
        var el = this;
        if (!el.name) return;
        var parsed = parseName(el.name);
        if (!parsed) return;
        var newVal = getValue(el);
        var oldVal = oldValues[el.name] !== undefined ? oldValues[el.name] : null;
        if (oldVal === newVal) return;
        oldValues[el.name] = newVal;
        sendChange(el, oldVal, newVal);
        if (parsed.scale !== null && newVal === null) {
            var otherScale = parsed.scale === 0 ? 1 : 0;
            var otherName = el.name.replace(/#\d+$/, '#' + otherScale);
            var otherOldVal = oldValues[otherName] !== undefined ? oldValues[otherName] : null;
            if (otherOldVal !== null) {
                var otherEl = document.querySelector('input[name="' + otherName + '"]');
                if (otherEl) {
                    oldValues[otherName] = null;
                    sendChange(otherEl, otherOldVal, null);
                }
            }
        }
    });

    // When a mask-question input receives focus, LimeSurvey's mask plugin reformats
    // the stored value (e.g. "42" → "42.00"), which triggers our setter. That setter
    // fire is not a user-intended change, so suppress it while the element is focusing.
    \$(document).off('focus.ualp').on('focus.ualp', 'input', function () {
        var el = this;
        if (!el.name || !parseName(el.name)) return;
        el._ualp_focusing = true;
        setTimeout(function () { el._ualp_focusing = false; }, 0);
    });

    var dateTimeQids = {$dateTimeQidsJs};

    \$('input').each(function () {
        if (!this.name || !parseName(this.name)) return;
        if (this.type === 'hidden') return;
        var el = this;
        var parsed = parseName(el.name);
        // Skip date/time inputs — the setter breaks datepicker-internal validation.
        // They are handled in the separate block below.
        if (parsed && dateTimeQids.indexOf(parsed.qid) !== -1) return;
        var proto = Object.getPrototypeOf(el);
        var descriptor = Object.getOwnPropertyDescriptor(proto, 'value');
        if (!descriptor || !descriptor.set) return;
        Object.defineProperty(el, 'value', {
            get: function () { return descriptor.get.call(el); },
            set: function (v) {
                descriptor.set.call(el, v);
                var newVal = (el.type === 'radio' || el.type === 'checkbox')
                    ? (el.checked && v !== '' ? v : null)
                    : (v !== '' ? v : null);
                var oldVal = oldValues[el.name] !== undefined ? oldValues[el.name] : null;
                if (oldVal === newVal) return;
                oldValues[el.name] = newVal;
                // Suppress logging for mask-plugin reformatting triggered on focus;
                // only the user's actual value change (fired on blur/change) should be logged.
                if (el._ualp_focusing) return;
                sendChange(el, oldVal, newVal);
            },
            configurable: true
        });
    });

    // Intercept programmatic `checked` changes on checkboxes (type-P multiple-choice-with-comments
    // auto-checks the checkbox when the user types into the comment field, without firing a `change`
    // event). setTimeout(0) defers so a real user-click `change` event can update oldValues first —
    // if it already did, oldValues matches and we skip; otherwise we log the missed state change.
    \$('input[type="checkbox"]').each(function () {
        if (!this.name || !parseName(this.name)) return;
        var el = this;
        var proto = Object.getPrototypeOf(el);
        var checkedDescriptor = Object.getOwnPropertyDescriptor(proto, 'checked');
        if (!checkedDescriptor || !checkedDescriptor.set) return;
        Object.defineProperty(el, 'checked', {
            get: function () { return checkedDescriptor.get.call(el); },
            set: function (v) {
                checkedDescriptor.set.call(el, v);
                setTimeout(function () {
                    var newVal = (el.checked && el.value !== '') ? el.value : null;
                    var oldVal = oldValues[el.name] !== undefined ? oldValues[el.name] : null;
                    if (oldVal === newVal) return;
                    oldValues[el.name] = newVal;
                    sendChange(el, oldVal, newVal);
                }, 0);
            },
            configurable: true
        });
    });

    // File upload questions store their data in a hidden input updated by LS's JS.
    // Hidden inputs are excluded from the generic override above, so patch them here.
    var fileUploadQids = {$fileUploadQidsJs};
    if (fileUploadQids.length) {
        \$('input[type="hidden"]').each(function () {
            var el = this;
            if (!el.name) return;
            var parsed = parseName(el.name);
            // Only target the base field (no sub/col suffix) for known file upload QIDs.
            if (!parsed || fileUploadQids.indexOf(parsed.qid) === -1 || parsed.sub !== null) return;
            oldValues[el.name] = el.value !== '' ? el.value : null;
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
    }

    // ── Date/time: completely separate handler ──────────────────────────────
    // Setter is skipped above to avoid breaking datepicker-internal validation.
    // Direct element listeners are used instead of document delegation because
    // LimeSurvey's datepicker uses triggerHandler (no bubbling). All bindings
    // use the .ualp namespace so a second IIFE run (e.g. after file upload)
    // removes the previous handlers before re-registering.
    if (dateTimeQids.length) {
        \$('input').each(function () {
            if (!this.name) return;
            var parsed = parseName(this.name);
            if (!parsed || dateTimeQids.indexOf(parsed.qid) === -1) return;
            var el = this;
            function logDateChange() {
                var newVal = el.value !== '' ? el.value : null;
                var oldVal = oldValues[el.name] !== undefined ? oldValues[el.name] : null;
                if (oldVal === newVal) return;
                oldValues[el.name] = newVal;
                sendChange(el, oldVal, newVal);
            }
            \$(el).off('.ualp-dt').on('dp.change.ualp-dt', logDateChange);
            \$(el).parent().off('.ualp-dt').on('dp.change.ualp-dt', logDateChange);
            \$(el).on('change.ualp-dt', logDateChange);
            \$(el).on('blur.ualp-dt', logDateChange);
        });
    }

    \$(document).off('click.ualp', '[data-limesurvey-submit*="saveall"]').on('click.ualp', '[data-limesurvey-submit*="saveall"]', function () {
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

        try {
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
        } catch (Exception $e) {
            error_log('[UALP] sub/col question lookup ERROR: ' . $e->getMessage());
        }

        $question    = null;
        $effectiveQid = $qid;
        $rankSubQid   = null;
        $resolvedInputType = null;

        try {
            $question = $qid ? Question::model()->findByPk($qid) : null;

            // Sub-questions (e.g. ranking positions) may be excluded by the model's
            // default scope; resolve the parent question and restructure identifiers
            // so question_id = parent qid, sub_question_id = rank-position qid.
            if (!$question && $qid) {
                $parentQid = (int) Yii::app()->db->createCommand()
                    ->select('parent_qid')
                    ->from(Yii::app()->db->tablePrefix . 'questions')
                    ->where('qid = :qid', [':qid' => $qid])
                    ->queryScalar();
                if ($parentQid) {
                    $question     = Question::model()->findByPk($parentQid);
                    $effectiveQid = $parentQid;
                    $rankSubQid   = $qid;
                }
            }

            // Fallback 2: ranking questions encode an answer ID (aid) in the field name rather
            // than a question ID. Look up the parent qid via lime_answers.
            if (!$question && $qid) {
                $answerQid = (int) Yii::app()->db->createCommand()
                    ->select('qid')
                    ->from(Yii::app()->db->tablePrefix . 'answers')
                    ->where('aid = :aid', [':aid' => $qid])
                    ->queryScalar();
                if ($answerQid) {
                    $question     = Question::model()->findByPk($answerQid);
                    $effectiveQid = $answerQid;
                    $rankSubQid   = $qid;
                }
            }

            // Fallback 3: ranking field name IDs are dynamically generated and exist in neither
            // lime_questions nor lime_answers. Find the ranking question (type='R') in the same
            // survey+group via raw SQL and treat the submitted qid as a ranked-item sub-identifier.
            if (!$question && $qid) {
                $gidParam = (int) $request->getParam('group_id');
                $rankQid  = (int) Yii::app()->db->createCommand()
                    ->select('qid')
                    ->from(Yii::app()->db->tablePrefix . 'questions')
                    ->where('sid = :sid AND gid = :gid AND type = :type AND parent_qid = 0', [
                        ':sid'  => $surveyId,
                        ':gid'  => $gidParam,
                        ':type' => 'R',
                    ])
                    ->queryScalar();
                if ($rankQid) {
                    // Dynamic field ID = parentQid . rankPosition (e.g. 10241 = qid 1024, position 1).
                    // Strip the parent prefix to get a human-readable rank position (1, 2, 3…).
                    $rankPosition      = (int) substr((string) $qid, strlen((string) $rankQid));
                    $effectiveQid      = $rankQid;
                    $rankSubQid        = $rankPosition ?: $qid;
                    $resolvedInputType = 'mask-question/ranking';
                }
            }
        } catch (Exception $e) {
            error_log('[UALP] question resolution ERROR: ' . $e->getMessage());
        }

        $specificType = $resolvedInputType
            ?? ($question
                ? (self::QUESTION_TYPES[$question->type] ?? $question->type)
                : $request->getParam('input_type'));

        $dualScale = $request->getParam('dual_scale');
        if ($dualScale !== null && $dualScale !== '') {
            $specificType .= '-' . (int) $dualScale;
        }

        $inputType = $specificType;

        $rawPage = $request->getParam('page_number');
        $this->writeLog([
            'survey_id'         => $surveyId,
            'participant_token' => $token,
            'event_type'        => 'answer_change',
            'page_number'       => ($rawPage !== null && $rawPage !== '') ? (int) $rawPage : null,
            'group_id'          => (int) $request->getParam('group_id'),
            'question_id'       => $effectiveQid ?: null,
            'sub_question_id'   => $subQid,
            'rank_position'     => ($inputType === 'mask-question/ranking' && $request->getParam('new_value') !== '') ? $rankSubQid : null,
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

        try {
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
                'rank_position'     => 'INTEGER',
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
        } catch (Exception $e) {
            error_log('[UALP] ensureTable ERROR: ' . $e->getMessage());
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

        try {
            if (!isset($schema->columns['column_id'])) {
                $db->createCommand()->addColumn($table, 'column_id', 'INTEGER');
            }

            if (!isset($schema->columns['rank_position'])) {
                $db->createCommand()->addColumn($table, 'rank_position', 'INTEGER');
            }
        } catch (Exception $e) {
            error_log('[UALP] ensureColumns ERROR: ' . $e->getMessage());
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
                    'rank_position'     => $data['rank_position'] ?? null,
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
