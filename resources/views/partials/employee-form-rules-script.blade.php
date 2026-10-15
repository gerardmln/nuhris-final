{{-- Hostinger-safe employee form cascading rules (does not depend on Vite). --}}
<script @cspNonce>
    (function () {
        if (window.__nuhrisEmployeeFormRulesLoaded) {
            if (typeof window.initializeEmployeeForms === 'function') {
                window.initializeEmployeeForms();
            }
            return;
        }
        window.__nuhrisEmployeeFormRulesLoaded = true;

        const normalize = (value) => String(value ?? '').trim().toLowerCase();

        function isPartTimeFacultyValue(value) {
            return normalize(value) === 'part-time faculty';
        }

        function isShsPosition(position) {
            return normalize(position).includes('(shs)');
        }

        function rankingPrefixForPosition(position) {
            const normalized = normalize(position);

            if (normalized.includes('assistant professor')) return 'assistant professor';
            if (normalized.includes('associate professor')) return 'associate professor';
            if (normalized.includes('full professor')) return 'full professor';
            if (normalized.includes('instructor')) return 'instructor';
            if (normalized.includes('master teacher')) return 'master teacher';
            if (normalized.includes('senior teacher')) return 'senior teacher';
            if (normalized.includes('teacher')) return 'teacher';

            return '';
        }

        function requiresGroupedRanking(position) {
            return rankingPrefixForPosition(position) !== '';
        }

        function getControl(form, name) {
            return form.querySelector(`[data-employee-control="${name}"]`);
        }

        function getField(form, name) {
            return form.querySelector(`[data-employee-field="${name}"]`);
        }

        function setFieldVisible(field, visible) {
            if (!field) return;
            // HTML hidden attribute works even if Tailwind/Vite CSS fails to load.
            field.hidden = !visible;
            field.classList.toggle('hidden', !visible);
            field.style.display = visible ? '' : 'none';
        }

        function filterRankingOptions(rankingControl, position) {
            if (!rankingControl) return;

            const prefix = rankingPrefixForPosition(position);
            const options = Array.from(rankingControl.options);
            let selectedStillVisible = false;

            options.forEach((option) => {
                if (option.value === '') {
                    option.hidden = false;
                    option.disabled = false;
                    if (option.value === rankingControl.value) selectedStillVisible = true;
                    return;
                }

                const optionValue = normalize(option.value);
                const matches = !prefix
                    || optionValue === prefix
                    || optionValue.startsWith(`${prefix} `);

                option.hidden = !matches;
                option.disabled = !matches;

                if (matches && option.value === rankingControl.value) {
                    selectedStillVisible = true;
                }
            });

            if (!selectedStillVisible && prefix) {
                rankingControl.value = '';
            }
        }

        function employmentCategory(type) {
            const normalized = normalize(type);
            if (!normalized) return '';
            if (isPartTimeFacultyValue(normalized)) return 'part-time-faculty';
            if (normalized.includes('faculty')) return 'faculty';
            if (normalized.includes('admin') || normalized === 'asp') return 'asp';
            return '';
        }

        function isShsDepartment(departmentControl) {
            const selected = departmentControl?.selectedOptions?.[0];

            return Boolean(selected && selected.value !== '' && normalize(selected.textContent).startsWith('shs'));
        }

        function positionMatchesCategory(option, category, departmentIsShs) {
            const optionCategory = option.dataset.employmentCategory || '';
            const isPartTimeOption = isPartTimeFacultyValue(option.value);

            if (category === '') return true;
            if (category === 'part-time-faculty') return isPartTimeOption;
            // Faculty positions follow the chosen department: SHS gets the
            // "(SHS)" positions, every college school gets the rest.
            if (category === 'faculty') {
                return optionCategory === 'faculty' && !isPartTimeOption && isShsPosition(option.value) === departmentIsShs;
            }
            if (category === 'asp') return optionCategory === 'asp';

            return optionCategory === category;
        }

        function filterPositionOptions(positionControl, employmentType, departmentIsShs) {
            if (!positionControl) return;

            const category = employmentCategory(employmentType);
            const options = Array.from(positionControl.options);
            let selectedStillVisible = false;

            options.forEach((option) => {
                if (option.value === '') {
                    option.hidden = false;
                    option.disabled = false;
                    return;
                }

                const matches = positionMatchesCategory(option, category, departmentIsShs);
                option.hidden = !matches;
                option.disabled = !matches;

                if (matches && option.value === positionControl.value) {
                    selectedStillVisible = true;
                }
            });

            if (!selectedStillVisible && category !== '') {
                positionControl.value = '';
            }

            // Part-time faculty has a single position; pick it automatically.
            const visibleOptions = options.filter((option) => option.value !== '' && !option.disabled);
            if (category === 'part-time-faculty' && visibleOptions.length === 1) {
                positionControl.value = visibleOptions[0].value;
            }
        }

        function updateEmployeeFormState(form) {
            if (!form) return;

            const employmentType = getControl(form, 'employment_type')?.value ?? '';
            const category = employmentCategory(employmentType);
            const positionField = getField(form, 'position');
            const positionControl = getControl(form, 'position');
            const departmentField = getField(form, 'department');
            const departmentControl = getControl(form, 'department');
            const departmentHidden = getControl(form, 'department_hidden');

            // Faculty pick their department first; Admin Support Personnel have none.
            const needsDepartment = category === 'faculty' || category === 'part-time-faculty';

            if (departmentField && departmentControl) {
                setFieldVisible(departmentField, needsDepartment);
                departmentControl.required = needsDepartment;
                departmentControl.disabled = false;

                if (!needsDepartment) {
                    departmentControl.value = '';
                }
            }

            if (departmentHidden) {
                departmentHidden.disabled = true;
            }

            // Position appears once its prerequisites are chosen. Unknown legacy
            // employee types keep the old behaviour of listing every position.
            const positionReady = category === 'asp'
                || (needsDepartment && (departmentControl?.value ?? '') !== '')
                || (category === '' && employmentType !== '');

            filterPositionOptions(positionControl, employmentType, isShsDepartment(departmentControl));

            if (positionControl) {
                setFieldVisible(positionField, positionReady);
                positionControl.required = positionReady;

                if (!positionReady) {
                    positionControl.value = '';
                }
            }

            const position = positionControl?.value ?? '';
            const rankingField = getField(form, 'ranking');
            const rankingControl = getControl(form, 'ranking');
            const employmentStatusControl = getControl(form, 'employment_status');
            const employmentStatusField = getField(form, 'employment_status');
            const employmentStatusHidden = getControl(form, 'employment_status_hidden');
            const employmentStatusHelp = form.querySelector('[data-employee-help="employment_status"]');

            const needsRanking = requiresGroupedRanking(position);

            if (rankingField && rankingControl) {
                filterRankingOptions(rankingControl, position);
                setFieldVisible(rankingField, needsRanking);
                rankingControl.required = needsRanking;

                if (!needsRanking) {
                    rankingControl.value = '';
                }
            }

            if (employmentStatusControl) {
                const isPartTime = isPartTimeFacultyValue(employmentType);
                setFieldVisible(employmentStatusField, !isPartTime);
                employmentStatusControl.disabled = isPartTime;
                if (employmentStatusHidden) {
                    employmentStatusHidden.name = isPartTime ? 'employment_status' : '';
                    employmentStatusHidden.value = 'probationary';
                    employmentStatusHidden.disabled = !isPartTime;
                }
                if (isPartTime) {
                    employmentStatusControl.value = 'probationary';
                    if (employmentStatusHelp) employmentStatusHelp.classList.remove('hidden');
                } else if (employmentStatusHelp) {
                    employmentStatusHelp.classList.add('hidden');
                }
            }
        }

        function initializeEmployeeForm(form) {
            if (!form || form.dataset.employeeFormBound === '1') {
                updateEmployeeFormState(form);
                return;
            }

            const typeControl = getControl(form, 'employment_type');
            const positionControl = getControl(form, 'position');
            const departmentControl = getControl(form, 'department');

            if (!positionControl) return;

            const handleChange = () => updateEmployeeFormState(form);

            positionControl.addEventListener('change', handleChange);

            if (typeControl) {
                typeControl.addEventListener('change', handleChange);
            }

            if (departmentControl) {
                departmentControl.addEventListener('change', handleChange);
            }

            form.dataset.employeeFormBound = '1';
            updateEmployeeFormState(form);
        }

        function initializeEmployeeForms() {
            document.querySelectorAll('[data-employee-form]').forEach((form) => {
                initializeEmployeeForm(form);
            });
        }

        window.updateEmployeeFormState = updateEmployeeFormState;
        window.initializeEmployeeForms = initializeEmployeeForms;

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initializeEmployeeForms);
        } else {
            initializeEmployeeForms();
        }

        document.addEventListener('click', (event) => {
            const trigger = event.target.closest('[data-open-modal]');
            if (!trigger) return;

            const modal = document.getElementById(trigger.getAttribute('data-open-modal'));
            if (!modal) return;

            setTimeout(() => {
                modal.querySelectorAll('[data-employee-form]').forEach((form) => {
                    initializeEmployeeForm(form);
                    updateEmployeeFormState(form);
                });
            }, 50);
        });
    })();
</script>

<script @cspNonce>
    (function () {
        function syncEmploymentStatusVisibility(form) {
            const typeControl = form.querySelector('[data-employee-control="employment_type"]');
            const statusField = form.querySelector('[data-employee-field="employment_status"]');
            const statusControl = form.querySelector('[data-employee-control="employment_status"]');
            const statusHidden = form.querySelector('[data-employee-control="employment_status_hidden"]');

            if (!typeControl || !statusField || !statusControl) {
                return;
            }

            const isPartTime = String(typeControl.value || '').trim().toLowerCase() === 'part-time faculty';
            statusField.hidden = isPartTime;
            statusField.style.display = isPartTime ? 'none' : '';
            statusControl.disabled = isPartTime;

            if (statusHidden) {
                statusHidden.name = isPartTime ? 'employment_status' : '';
                statusHidden.value = 'probationary';
                statusHidden.disabled = !isPartTime;
            }

            if (isPartTime) {
                statusControl.value = 'probationary';
            }
        }

        function initializeEmploymentStatusVisibility() {
            document.querySelectorAll('[data-employee-form]').forEach((form) => {
                const typeControl = form.querySelector('[data-employee-control="employment_type"]');

                if (typeControl && typeControl.dataset.employmentStatusBound !== '1') {
                    typeControl.addEventListener('change', () => syncEmploymentStatusVisibility(form));
                    typeControl.dataset.employmentStatusBound = '1';
                }

                syncEmploymentStatusVisibility(form);
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initializeEmploymentStatusVisibility);
        } else {
            initializeEmploymentStatusVisibility();
        }

        document.addEventListener('click', () => {
            setTimeout(initializeEmploymentStatusVisibility, 0);
        });
    })();
</script>
