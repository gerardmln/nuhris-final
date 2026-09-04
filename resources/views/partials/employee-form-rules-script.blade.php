{{-- Hostinger-safe employee form cascading rules (does not depend on Vite). --}}
<script>
    (function () {
        if (window.__nuhrisEmployeeFormRulesLoaded) {
            if (typeof window.initializeEmployeeForms === 'function') {
                window.initializeEmployeeForms();
            }
            return;
        }
        window.__nuhrisEmployeeFormRulesLoaded = true;

        const normalize = (value) => String(value ?? '').trim().toLowerCase();
        const teachingKeywords = ['professor', 'dean', 'program chair', 'instructor'];
        const shsKeywords = ['(shs)'];

        function isPartTimeFacultyValue(value) {
            return normalize(value) === 'part-time faculty';
        }

        function isTeachingPosition(position) {
            const normalized = normalize(position);

            return teachingKeywords.some((keyword) => normalized.includes(keyword))
                || shsKeywords.some((keyword) => normalized.includes(keyword))
                || isPartTimeFacultyValue(normalized);
        }

        function needsDepartmentSelection(employmentType, position) {
            return isTeachingPosition(position) || isPartTimeFacultyValue(employmentType);
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

        function positionMatchesCategory(option, category) {
            const optionCategory = option.dataset.employmentCategory || '';
            const isPartTimeOption = isPartTimeFacultyValue(option.value);

            if (category === '') return true;
            if (category === 'part-time-faculty') return isPartTimeOption;
            if (category === 'faculty') return optionCategory === 'faculty' && !isPartTimeOption;
            if (category === 'asp') return optionCategory === 'asp';

            return optionCategory === category;
        }

        function filterPositionOptions(positionControl, employmentType) {
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

                const matches = positionMatchesCategory(option, category);
                option.hidden = !matches;
                option.disabled = !matches;

                if (matches && option.value === positionControl.value) {
                    selectedStillVisible = true;
                }
            });

            if (!selectedStillVisible && category !== '') {
                positionControl.value = '';
            }
        }

        function updateEmployeeFormState(form) {
            if (!form) return;

            const employmentType = getControl(form, 'employment_type')?.value ?? '';
            const positionControl = getControl(form, 'position');

            filterPositionOptions(positionControl, employmentType);

            const position = positionControl?.value ?? '';
            const departmentField = getField(form, 'department');
            const departmentControl = getControl(form, 'department');
            const rankingField = getField(form, 'ranking');
            const rankingControl = getControl(form, 'ranking');
            const departmentHidden = getControl(form, 'department_hidden');

            const needsDepartment = needsDepartmentSelection(employmentType, position);
            const needsRanking = requiresGroupedRanking(position);
            const isShs = isShsPosition(position);

            if (departmentField && departmentControl) {
                setFieldVisible(departmentField, needsDepartment);
                departmentControl.required = needsDepartment && !isShs;
                departmentControl.disabled = isShs;

                if (isShs) {
                    const shsOption = Array.from(departmentControl.options).find(
                        (opt) => normalize(opt.textContent).includes('shs')
                    );

                    if (shsOption) {
                        departmentControl.value = shsOption.value;
                    }
                } else if (!needsDepartment) {
                    departmentControl.value = '';
                }

                if (departmentHidden) {
                    departmentHidden.value = departmentControl.value;
                    departmentHidden.disabled = !isShs;
                }
            }

            if (rankingField && rankingControl) {
                filterRankingOptions(rankingControl, position);
                setFieldVisible(rankingField, needsRanking);
                rankingControl.required = needsRanking;

                if (!needsRanking) {
                    rankingControl.value = '';
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

            if (!positionControl) return;

            const handleChange = () => updateEmployeeFormState(form);

            positionControl.addEventListener('change', handleChange);
            positionControl.addEventListener('focus', handleChange);
            positionControl.addEventListener('mousedown', handleChange);

            if (typeControl) {
                typeControl.addEventListener('change', handleChange);
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
