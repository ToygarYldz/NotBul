(() => {
    'use strict';

    const CLASS_OPTIONS = [
        { id: '1', name: '1. Sınıf' },
        { id: '2', name: '2. Sınıf' },
        { id: '3', name: '3. Sınıf' },
        { id: '4', name: '4. Sınıf' }
    ];

    const REMOTE = {
        universities: [],
        universitiesById: new Map(),
        departmentsByType: {
            lisans: [],
            onlisans: []
        },
        departmentsById: new Map()
    };

    async function loadRemoteFilterData() {
        try {
            const [universitiesResponse, departmentsResponse] = await Promise.all([
                fetch('assets/data/universiteler.json'),
                fetch('assets/data/bolumler.json')
            ]);

            if (!universitiesResponse.ok || !departmentsResponse.ok) {
                throw new Error('Json dosyalari okunamadi');
            }

            const universities = await universitiesResponse.json();
            const departments = await departmentsResponse.json();

            REMOTE.universities = Array.isArray(universities) ? universities : [];
            REMOTE.universitiesById = new Map(REMOTE.universities.map((item) => [item.id, item]));

            REMOTE.departmentsByType = {
                lisans: Array.isArray(departments.lisans) ? departments.lisans : [],
                onlisans: Array.isArray(departments.onlisans) ? departments.onlisans : []
            };
            REMOTE.departmentsById = new Map(
                [...REMOTE.departmentsByType.lisans, ...REMOTE.departmentsByType.onlisans].map((item) => [item.id, item])
            );
        } catch (error) {
            REMOTE.universities = [];
            REMOTE.universitiesById = new Map(REMOTE.universities.map((item) => [item.id, item]));
            REMOTE.departmentsByType = {
                lisans: [],
                onlisans: []
            };
            REMOTE.departmentsById = new Map();
        }
    }

    function normalize(value) {
        return (value || '').toString().trim().toLowerCase();
    }

    function escapeHtml(value) {
        return (value || '').toString()
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatDate(dateValue) {
        const date = new Date(dateValue);
        return new Intl.DateTimeFormat('tr-TR', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric'
        }).format(date);
    }

    function formatNumber(numberValue) {
        return new Intl.NumberFormat('tr-TR').format(numberValue || 0);
    }

    function formatRatingAverage(ratingValue) {
        const rating = Number.parseFloat(ratingValue);
        if (!Number.isFinite(rating)) {
            return '';
        }

        return new Intl.NumberFormat('tr-TR', {
            minimumFractionDigits: 1,
            maximumFractionDigits: 1
        }).format(Math.min(5, Math.max(1, rating)));
    }

    function ratingSummaryTemplate(note, showCount = false) {
        const count = Number.parseInt(note.ratingCount || 0, 10);
        const average = Number.parseFloat(note.ratingAverage);

        if (!count || !Number.isFinite(average)) {
            return '';
        }

        const countLabel = count === 1 ? '1 değerlendirme' : `${formatNumber(count)} değerlendirme`;
        const countHtml = showCount ? `<span class="rating-count">(${formatNumber(count)})</span>` : '';

        return `
            <span class="rating-summary" title="${escapeHtml(countLabel)}">
                <i class="fa-solid fa-star" aria-hidden="true"></i>
                <span class="rating-score">${escapeHtml(formatRatingAverage(average))}</span>
                ${countHtml}
            </span>
        `;
    }

    function ratingStarsTemplate(ratingValue, showValue = true) {
        const parsedRating = Number.parseInt(ratingValue || 0, 10);
        const rating = Number.isFinite(parsedRating)
            ? Math.min(5, Math.max(1, parsedRating))
            : 1;
        const stars = Array.from({ length: 5 }, (_, index) => {
            const iconClass = index < rating ? 'fa-solid' : 'fa-regular';
            return `<i class="${iconClass} fa-star" aria-hidden="true"></i>`;
        }).join('');
        const valueHtml = showValue ? `<span class="rating-value">${rating}/5</span>` : '';

        return `
            <span class="rating-stars-wrap">
                <span class="rating-stars" aria-label="${rating}/5">${stars}</span>
                ${valueHtml}
            </span>
        `;
    }

    function resolveUniversityName(id) {
        const remote = REMOTE.universitiesById.get(id);
        if (remote) {
            return remote.name;
        }
        return '-';
    }

    function resolveDepartmentName(id) {
        const remote = REMOTE.departmentsById.get(id);
        if (remote) {
            return remote.name;
        }
        return '-';
    }

    function resolveCourseName(note) {
        return (note.course || '').toString();
    }

    function resolveTopicName(note) {
        return (note.topic || '').toString();
    }

    function getAllNotes() {
        if (Array.isArray(window.NOTBUL_NOTES)) {
            return window.NOTBUL_NOTES;
        }
        return [];
    }

    function getUniqueOptions(values) {
        return [...new Set(values.map((value) => value.trim()).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'tr'));
    }

    function populateDatalist(datalist, values) {
        if (!datalist) {
            return;
        }
        datalist.innerHTML = values.map((value) => `<option value="${escapeHtml(value)}"></option>`).join('');
    }

    function populateSelect(select, items, placeholder, keepCurrent) {
        if (!select) {
            return;
        }

        const current = keepCurrent ? select.value : '';
        const options = [`<option value="">${escapeHtml(placeholder)}</option>`]
            .concat(items.map((item) => `<option value="${escapeHtml(item.id)}">${escapeHtml(item.name)}</option>`));

        select.innerHTML = options.join('');

        if (keepCurrent && current && items.some((item) => item.id === current)) {
            select.value = current;
        }
    }
    function initHierarchyGroups() {
        document.querySelectorAll('[data-hierarchy-group]').forEach((group) => {
            initHierarchyGroup(group);
        });
    }

    function initHierarchyGroup(group) {
        initPublicFilterGroup(group);
    }

    function initPublicFilterGroup(group) {
        const university = group.querySelector('select[data-level="university"]');
        const departmentType = group.querySelector('select[data-level="department-type"]');
        const department = group.querySelector('select[data-level="department"]');
        const classSelect = group.querySelector('select[data-level="class"]');
        const courseSelect = group.querySelector('select[data-level="course"]');
        const topicSelect = group.querySelector('select[data-level="topic"]');
        const courseInput = group.querySelector('input[data-level="course-input"]');
        const topicInput = group.querySelector('input[data-level="topic-input"]');
        const courseDatalist = group.querySelector('#uploadCourseList');
        const topicDatalist = group.querySelector('#uploadTopicList');
        const allNotes = getAllNotes();
        const useNotesScopedOptions = group.dataset.optionsScope === 'notes' && allNotes.length > 0;
        const departmentTypeOptions = [
            { id: 'onlisans', name: 'Önlisans' },
            { id: 'lisans', name: 'Lisans' }
        ];

        const getAvailableIds = (notes, key) => new Set(
            notes
                .map((note) => (note[key] || '').toString().trim())
                .filter(Boolean)
        );

        const refreshUniversities = () => {
            if (!university) {
                return;
            }

            if (!useNotesScopedOptions) {
                populateSelect(university, REMOTE.universities, university.dataset.placeholder || 'Seçiniz', true);
                return;
            }

            const availableUniversityIds = getAvailableIds(allNotes, 'universityId');
            const missingUniversities = [...availableUniversityIds]
                .filter((id) => !REMOTE.universitiesById.has(id))
                .map((id) => ({ id, name: id }));
            const list = REMOTE.universities
                .filter((item) => availableUniversityIds.has(item.id))
                .concat(missingUniversities)
                .sort((a, b) => a.name.localeCompare(b.name, 'tr'));

            populateSelect(university, list, university.dataset.placeholder || 'Seçiniz', true);
        };

        const refreshDepartmentTypes = () => {
            if (!departmentType) {
                return;
            }

            if (!useNotesScopedOptions) {
                populateSelect(
                    departmentType,
                    departmentTypeOptions,
                    departmentType.dataset.placeholder || 'Seçiniz',
                    true
                );
                return;
            }

            const selectedUniversity = university ? university.value : '';
            const scopedNotes = allNotes.filter((note) => {
                if (selectedUniversity && note.universityId !== selectedUniversity) {
                    return false;
                }
                return true;
            });
            const availableDepartmentTypes = getAvailableIds(scopedNotes, 'departmentType');
            const list = departmentTypeOptions.filter((item) => availableDepartmentTypes.has(item.id));

            populateSelect(
                departmentType,
                list,
                departmentType.dataset.placeholder || 'Seçiniz',
                true
            );
        };

        populateSelect(classSelect, CLASS_OPTIONS, classSelect?.dataset.placeholder || 'Seçiniz', true);

        const refreshDepartments = () => {
            if (!department) {
                return;
            }

            const selectedType = departmentType ? departmentType.value : '';
            const placeholder = selectedType
                ? (department.dataset.placeholder || 'Seçiniz')
                : 'Önce program türü seçiniz';

            if (!selectedType) {
                populateSelect(department, [], placeholder, true);
                return;
            }

            if (!useNotesScopedOptions) {
                const list = REMOTE.departmentsByType[selectedType] || [];
                populateSelect(department, list, placeholder, true);
                return;
            }

            const selectedUniversity = university ? university.value : '';
            const scopedNotes = allNotes.filter((note) => {
                if (note.departmentType !== selectedType) {
                    return false;
                }
                if (selectedUniversity && note.universityId !== selectedUniversity) {
                    return false;
                }
                return true;
            });
            const availableDepartmentIds = getAvailableIds(scopedNotes, 'departmentId');
            const baseList = REMOTE.departmentsByType[selectedType] || [];
            const missingDepartments = [...availableDepartmentIds]
                .filter((id) => !REMOTE.departmentsById.has(id))
                .map((id) => ({ id, name: id }));
            const list = baseList
                .filter((item) => availableDepartmentIds.has(item.id))
                .concat(missingDepartments)
                .sort((a, b) => a.name.localeCompare(b.name, 'tr'));

            populateSelect(department, list, placeholder, true);
        };

        const getScopedNotes = () => {
            const selectedUniversity = university ? university.value : '';
            const selectedDepartmentType = departmentType ? departmentType.value : '';
            const selectedDepartment = department ? department.value : '';
            const selectedClass = classSelect ? classSelect.value : '';

            return getAllNotes().filter((note) => {
                if (selectedUniversity && note.universityId !== selectedUniversity) {
                    return false;
                }
                if (selectedDepartmentType && note.departmentType !== selectedDepartmentType) {
                    return false;
                }
                if (selectedDepartment && note.departmentId !== selectedDepartment) {
                    return false;
                }
                if (selectedClass && note.classId !== selectedClass) {
                    return false;
                }
                return true;
            });
        };

        const refreshCourse = () => {
            const notes = getScopedNotes();
            const courseValues = getUniqueOptions(notes.map((note) => resolveCourseName(note)));

            if (!courseSelect && !courseInput) {
                return;
            }

            if (courseSelect) {
                const list = courseValues.map((value) => ({ id: value, name: value }));
                populateSelect(courseSelect, list, courseSelect.dataset.placeholder || 'Seçiniz', true);
            }
            if (courseInput) {
                populateDatalist(courseDatalist, courseValues);
            }
        };

        const refreshTopic = () => {
            const selectedCourse = courseSelect ? courseSelect.value : (courseInput ? courseInput.value : '');
            let notes = getScopedNotes();

            if (selectedCourse) {
                notes = notes.filter((note) => normalize(resolveCourseName(note)) === normalize(selectedCourse));
            }

            const topicValues = getUniqueOptions(notes.map((note) => resolveTopicName(note)));
            if (!topicSelect && !topicInput) {
                return;
            }

            if (topicSelect) {
                const list = topicValues.map((value) => ({ id: value, name: value }));
                populateSelect(topicSelect, list, topicSelect.dataset.placeholder || 'Seçiniz', true);
            }
            if (topicInput) {
                populateDatalist(topicDatalist, topicValues);
            }
        };

        refreshUniversities();
        refreshDepartmentTypes();
        refreshDepartments();
        refreshCourse();
        refreshTopic();

        departmentType?.addEventListener('change', () => {
            refreshDepartments();
            refreshCourse();
            refreshTopic();
            group.dispatchEvent(new Event('hierarchy:changed'));
        });

        university?.addEventListener('change', () => {
            refreshDepartmentTypes();
            refreshDepartments();
            refreshCourse();
            refreshTopic();
            group.dispatchEvent(new Event('hierarchy:changed'));
        });

        department?.addEventListener('change', () => {
            refreshCourse();
            refreshTopic();
            group.dispatchEvent(new Event('hierarchy:changed'));
        });

        classSelect?.addEventListener('change', () => {
            refreshCourse();
            refreshTopic();
            group.dispatchEvent(new Event('hierarchy:changed'));
        });

        courseSelect?.addEventListener('change', () => {
            refreshTopic();
            group.dispatchEvent(new Event('hierarchy:changed'));
        });

        courseInput?.addEventListener('input', () => {
            refreshTopic();
            group.dispatchEvent(new Event('hierarchy:changed'));
        });

        topicSelect?.addEventListener('change', () => {
            group.dispatchEvent(new Event('hierarchy:changed'));
        });
        topicInput?.addEventListener('input', () => {
            group.dispatchEvent(new Event('hierarchy:changed'));
        });

    }

    function collectFilters(form) {
        const formData = new FormData(form);
        return {
            query: normalize(formData.get('query')),
            universityId: normalize(formData.get('university_id')),
            facultyId: normalize(formData.get('faculty_id')),
            departmentType: normalize(formData.get('department_type')),
            departmentId: normalize(formData.get('department_id')),
            classId: normalize(formData.get('class_id')),
            course: normalize(formData.get('course') || formData.get('course_id')),
            topic: normalize(formData.get('topic') || formData.get('topic_id')),
            fileType: normalize(formData.get('file_type'))
        };
    }

    function matchesFilters(note, filters) {
        if (filters.universityId && note.universityId !== filters.universityId) {
            return false;
        }
        if (filters.facultyId && note.facultyId !== filters.facultyId) {
            return false;
        }
        if (filters.departmentType && note.departmentType !== filters.departmentType) {
            return false;
        }
        if (filters.departmentId && note.departmentId !== filters.departmentId) {
            return false;
        }
        if (filters.classId && note.classId !== filters.classId) {
            return false;
        }
        if (filters.course && normalize(resolveCourseName(note)) !== filters.course) {
            return false;
        }
        if (filters.topic && normalize(resolveTopicName(note)) !== filters.topic) {
            return false;
        }
        if (filters.fileType && note.fileType !== filters.fileType) {
            return false;
        }

        if (filters.query) {
            const searchable = [
                note.title,
                note.description,
                (note.tags || []).join(' '),
                resolveCourseName(note),
                resolveTopicName(note),
                resolveDepartmentName(note.departmentId),
                resolveUniversityName(note.universityId)
            ].join(' ').toLowerCase();

            if (!searchable.includes(filters.query)) {
                return false;
            }
        }

        return true;
    }

    function filterNotes(filters, notes = getAllNotes()) {
        return notes.filter((note) => matchesFilters(note, filters));
    }

    function shortenText(value, maxLength = 80) {
        const text = (value || '').toString().trim();
        if (!text) {
            return 'Açıklama eklenmemiş.';
        }
        if (text.length <= maxLength) {
            return text;
        }
        return `${text.slice(0, maxLength)}...`;
    }

    function noteCardTemplate(note) {
        const tagHtml = (note.tags || [])
            .slice(0, 2)
            .map((tag) => `<span class="badge bg-light text-secondary fw-normal">#${escapeHtml(tag)}</span>`)
            .join('');
        const course = resolveCourseName(note) || '-';
        const ratingHtml = ratingSummaryTemplate(note);

        return `
            <article class="col-sm-6 col-xl-4">
                <div class="note-card card shadow-sm border-0">
                    <div class="card-body">
                        <h3 class="h6 mb-2 text-truncate">${escapeHtml(note.title)}</h3>
                        <p class="text-secondary mb-3 small" style="height: 3em; overflow: hidden;">
                            ${escapeHtml(shortenText(note.description))}
                        </p>
                        <div class="note-tags mb-3">${tagHtml}</div>
                        <div class="note-card-footer d-flex justify-content-between align-items-center gap-3">
                            <div class="small">
                                <div class="fw-bold text-dark">${escapeHtml(note.uploader || '-')}</div>
                                <div class="text-secondary">${escapeHtml(course)}</div>
                            </div>
                            <div class="note-card-actions d-flex align-items-center gap-2 ms-auto">
                                ${ratingHtml}
                                <a href="note-detail.php?id=${note.id}" class="btn btn-sm btn-primary">Detay</a>
                            </div>
                        </div>
                    </div>
                </div>
            </article>
        `;
    }

    function renderGrid(gridElement, notes, emptyMessage) {
        if (!gridElement) {
            return;
        }

        if (!notes.length) {
            gridElement.innerHTML = `<div class="col-12"><div class="empty-state">${escapeHtml(emptyMessage)}</div></div>`;
            return;
        }

        gridElement.innerHTML = notes.map((note) => noteCardTemplate(note)).join('');
    }
    function initHomePage() {
        const form = document.getElementById('homeFilterForm');
        if (!form) {
            return;
        }

        const popularGrid = document.getElementById('popularNotesGrid');
        const primaryTitle = document.getElementById('homePrimaryPanelTitle');
        const resultCount = document.getElementById('homeResultCount');

        const hasActiveSearch = (filters) => Object.values(filters).some((value) => value !== '');

        const render = () => {
            const filters = collectFilters(form);
            const filtered = filterNotes(filters);
            const searchActive = hasActiveSearch(filters);

            const notesToShow = searchActive
                ? [...filtered].sort((a, b) => new Date(b.createdAt) - new Date(a.createdAt)).slice(0, 6)
                : [...getAllNotes()]
                    .sort((a, b) => {
                        const downloadDiff = (b.downloads || 0) - (a.downloads || 0);
                        if (downloadDiff !== 0) {
                            return downloadDiff;
                        }
                        return new Date(b.createdAt) - new Date(a.createdAt);
                    })
                    .slice(0, 6);

            renderGrid(
                popularGrid,
                notesToShow,
                searchActive ? 'Arama kriterlerine uygun not bulunamadı.' : 'Henüz popüler not bulunmuyor.'
            );

            if (primaryTitle) {
                primaryTitle.textContent = searchActive ? 'Arama Sonuçları' : 'Popüler Notlar';
            }

            if (resultCount) {
                resultCount.textContent = formatNumber(filtered.length);
            }
        };

        form.addEventListener('input', render);
        form.addEventListener('change', render);
        form.addEventListener('hierarchy:changed', render);
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            render();
        });
        render();
    }

    function initTagInputs() {
        document.querySelectorAll('[data-tag-input]').forEach((container) => {
            const chipsContainer = container.querySelector('[data-tag-chips]');
            const textField = container.querySelector('[data-tag-field]');
            const hiddenField = container.querySelector('[data-tag-hidden]');

            if (!chipsContainer || !textField || !hiddenField) {
                return;
            }

            let tags = [];

            const normalizeTag = (rawValue) => {
                return (rawValue || '')
                    .toString()
                    .trim()
                    .toLocaleLowerCase('tr-TR')
                    .replace(/\s+/g, '-')
                    .replace(/[^0-9a-zçğıöşü-]/g, '')
                    .replace(/-+/g, '-')
                    .replace(/^-+|-+$/g, '');
            };

            const sync = () => {
                hiddenField.value = tags.join(',');
                chipsContainer.innerHTML = tags.map((tag) => {
                    const safeTag = escapeHtml(tag);
                    return `<span class="tag-chip">${safeTag}<button type="button" data-remove-tag="${safeTag}">&times;</button></span>`;
                }).join('');
            };

            const addTag = (rawValue) => {
                const cleanValue = normalizeTag(rawValue);
                if (!cleanValue || tags.includes(cleanValue) || tags.length >= 12) {
                    return;
                }
                tags.push(cleanValue);
                sync();
            };

            textField.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter' && event.key !== ',') {
                    return;
                }

                event.preventDefault();
                addTag(textField.value);
                textField.value = '';
            });

            textField.addEventListener('blur', () => {
                if (!textField.value) {
                    return;
                }
                addTag(textField.value);
                textField.value = '';
            });

            chipsContainer.addEventListener('click', (event) => {
                const target = event.target;
                if (!(target instanceof HTMLButtonElement)) {
                    return;
                }
                const tagValue = target.dataset.removeTag;
                if (!tagValue) {
                    return;
                }
                tags = tags.filter((tag) => tag !== normalizeTag(tagValue));
                sync();
            });

            container.addEventListener('tag:clear', () => {
                tags = [];
                textField.value = '';
                sync();
            });

            sync();
        });
    }

    function initUploadPage() {
        const uploadForm = document.getElementById('uploadForm');
        if (!uploadForm) {
            return;
        }

        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('noteFile');
        const fileList = document.getElementById('fileList');
        const notice = document.getElementById('uploadNotice');
        const pickFileButton = document.getElementById('pickFileButton');

        const configuredMaxMb = Number.parseInt(uploadForm.dataset.maxUploadMb || '25', 10);
        const maxUploadMb = Number.isFinite(configuredMaxMb) && configuredMaxMb > 0 ? configuredMaxMb : 25;
        const maxBytes = maxUploadMb * 1024 * 1024;
        const acceptedExtensions = new Set(['pdf', 'docx', 'pptx', 'png', 'jpg', 'jpeg', 'webp']);
        const showNotice = (message, type) => {
            if (!notice) {
                return;
            }

            notice.classList.remove('d-none', 'alert-success', 'alert-danger', 'alert-info');
            notice.classList.add(`alert-${type}`);
            notice.textContent = message;
        };

        const clearNotice = () => {
            if (!notice) {
                return;
            }
            notice.classList.add('d-none');
            notice.textContent = '';
        };

        const renderSelectedFile = (file) => {
            if (!fileList) {
                return;
            }
            fileList.innerHTML = `
                <div class="file-item"><strong>${escapeHtml(file.name)}</strong></div>
                <div class="file-item">Boyut: ${formatNumber(Math.ceil(file.size / 1024))} KB</div>
                <div class="file-item">Tür: ${escapeHtml(file.type || 'Bilinmiyor')}</div>
            `;
        };

        const validateFile = (file) => {
            const errors = [];
            const extension = normalize(file.name.split('.').pop());

            if (!acceptedExtensions.has(extension)) {
                errors.push('Desteklenmeyen dosya uzantısı.');
            }

            if (file.size > maxBytes) {
                errors.push(`Dosya ${maxUploadMb} MB limitini aşıyor.`);
            }

            return errors;
        };

        const handleSelectedFile = (file) => {
            if (!file) {
                return;
            }

            const errors = validateFile(file);
            if (errors.length) {
                showNotice(errors.join(' '), 'danger');
                if (fileInput) {
                    fileInput.value = '';
                }
                if (fileList) {
                    fileList.innerHTML = '';
                }
                return;
            }

            renderSelectedFile(file);
            showNotice('Dosya seçildi. Yüklemek için "Dosyayı Yükle" butonuna tıklayın.', 'success');
        };

        const preventDefaults = (event) => {
            event.preventDefault();
            event.stopPropagation();
        };

        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach((eventName) => {
            dropZone?.addEventListener(eventName, preventDefaults);
        });

        ['dragenter', 'dragover'].forEach((eventName) => {
            dropZone?.addEventListener(eventName, () => dropZone.classList.add('drag-over'));
        });

        ['dragleave', 'drop'].forEach((eventName) => {
            dropZone?.addEventListener(eventName, () => dropZone.classList.remove('drag-over'));
        });

        dropZone?.addEventListener('drop', (event) => {
            const transfer = event.dataTransfer;
            if (!transfer || !transfer.files || !transfer.files.length) {
                return;
            }
            if (fileInput) {
                fileInput.files = transfer.files;
            }
            handleSelectedFile(transfer.files[0]);
        });

        pickFileButton?.addEventListener('click', () => fileInput?.click());
        fileInput?.addEventListener('change', () => {
            clearNotice();
            handleSelectedFile(fileInput.files?.[0]);
        });

        uploadForm.addEventListener('submit', (event) => {
            const selectedFile = fileInput?.files?.[0];
            if (!selectedFile) {
                event.preventDefault();
                showNotice('Lütfen önce bir dosya seçin.', 'danger');
                return;
            }

            const formData = new FormData(uploadForm);
            const courseValue = (formData.get('course') || '').toString().trim();
            if (!courseValue) {
                event.preventDefault();
                showNotice('Ders alanı zorunlu. Lütfen ders bilgisini girin.', 'danger');
                return;
            }
        });
    }
    function initNoteDetailPage() {
        const form = document.getElementById('commentForm');
        const commentsList = document.getElementById('commentsList');

        if (!form || !commentsList) {
            return;
        }

        form.addEventListener('submit', (event) => {
            event.preventDefault();

            const authorField = document.getElementById('commentAuthor');
            const ratingField = document.getElementById('commentRating');
            const textField = document.getElementById('commentText');

            if (!(authorField instanceof HTMLInputElement) || !(ratingField instanceof HTMLSelectElement) || !(textField instanceof HTMLTextAreaElement)) {
                return;
            }

            const author = authorField.value.trim();
            const rating = normalize(ratingField.value);
            const text = textField.value.trim();

            if (!author || !rating || !text) {
                return;
            }

            const item = document.createElement('article');
            item.className = 'comment-item';
            item.innerHTML = `
                <header><strong>${escapeHtml(author)}</strong> ${ratingStarsTemplate(rating)}</header>
                <p class="mb-0">${escapeHtml(text)}</p>
            `;

            commentsList.prepend(item);
            form.reset();
        });
    }

    function initSearchPage() {
        const form = document.getElementById('searchFilterForm');
        const queryInput = document.getElementById('searchQuery');
        const resultsContainer = document.getElementById('searchResults');
        const pagination = document.getElementById('searchPagination');
        const countElement = document.getElementById('searchResultCount');

        if (!form || !resultsContainer || !pagination || !countElement) {
            return;
        }

        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('q') && queryInput) {
            queryInput.value = urlParams.get('q');
        }

        const similarToId = parseInt(urlParams.get('similar_to'), 10);
        let similarNote = null;
        if (!isNaN(similarToId) && similarToId > 0 && Array.isArray(window.NOTBUL_NOTES)) {
            similarNote = window.NOTBUL_NOTES.find(n => n.id === similarToId);
        }

        const state = {
            currentPage: 1,
            pageSize: 5,
            filtered: []
        };

        const drawPagination = () => {
            const totalPages = Math.max(1, Math.ceil(state.filtered.length / state.pageSize));
            state.currentPage = Math.min(state.currentPage, totalPages);

            const buttons = [];
            for (let page = 1; page <= totalPages; page += 1) {
                buttons.push(`
                    <li class="page-item ${page === state.currentPage ? 'active' : ''}">
                        <button type="button" class="page-link" data-page="${page}">${page}</button>
                    </li>
                `);
            }

            pagination.innerHTML = buttons.join('');
        };

        const drawResults = () => {
            const start = (state.currentPage - 1) * state.pageSize;
            const pageItems = state.filtered.slice(start, start + state.pageSize);

            if (!pageItems.length) {
                resultsContainer.innerHTML = '<div class="empty-state">Filtreye uygun not bulunamadı.</div>';
                countElement.textContent = '0';
                drawPagination();
                return;
            }

            resultsContainer.innerHTML = pageItems.map((note) => {
                const ratingHtml = ratingSummaryTemplate(note, true);

                return `
                    <article class="result-item">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <h3 class="h5 mb-1">${escapeHtml(note.title)}</h3>
                                <p class="mb-2 text-secondary">${escapeHtml(note.description)}</p>
                            </div>
                            <a href="note-detail.php?id=${note.id}" class="btn btn-sm btn-outline-primary whitespace-nowrap">Detay</a>
                        </div>
                        <div class="result-footer">
                            <div class="d-flex flex-wrap gap-2">
                                <span class="note-tag">${escapeHtml(resolveUniversityName(note.universityId))}</span>
                                <span class="note-tag">${escapeHtml(resolveCourseName(note))}</span>
                                <span class="note-tag">${escapeHtml(resolveTopicName(note))}</span>
                            </div>
                            <div class="result-stats text-secondary small">
                                ${ratingHtml}
                                <span>${formatDate(note.createdAt)} | ${formatNumber(note.downloads)} indirme</span>
                            </div>
                        </div>
                    </article>
                `;
            }).join('');

            countElement.textContent = formatNumber(state.filtered.length);
            drawPagination();
        };

        const apply = () => {
            const filters = collectFilters(form);
            if (queryInput instanceof HTMLInputElement) {
                filters.query = normalize(queryInput.value);
            }

            let isCustomSort = false;
            let resultNotes = filterNotes(filters);

            if (similarNote && !filters.query && !Object.values(filters).some(v => v && typeof v === 'string' && v.trim() !== '')) {
                const targetTags = (similarNote.tags || []).map(normalize).filter(Boolean);
                const targetTitleWords = normalize(similarNote.title).split(/\s+/).filter(w => w.length > 2);
                
                resultNotes = resultNotes.filter(note => {
                    if (note.id === similarNote.id) return false;
                    
                    const noteTags = (note.tags || []).map(normalize);
                    const noteTitleWords = normalize(note.title).split(/\s+/);
                    
                    let score = 0;
                    const sharedTags = noteTags.filter(t => targetTags.includes(t));
                    score += sharedTags.length * 10;
                    
                    const sharedTitleWords = noteTitleWords.filter(w => w.length > 2 && targetTitleWords.includes(w));
                    score += sharedTitleWords.length * 2;
                    
                    if (note.course === similarNote.course && similarNote.course) score += 5;
                    
                    if (score > 0) {
                        note._similarScore = score;
                        return true;
                    }
                    return false;
                });
                
                resultNotes.sort((a, b) => b._similarScore - a._similarScore);
                isCustomSort = true;
            }

            if (!isCustomSort) {
                resultNotes.sort((a, b) => new Date(b.createdAt) - new Date(a.createdAt));
            }

            state.filtered = resultNotes;
            state.currentPage = 1;
            drawResults();
        };

        form.addEventListener('change', apply);
        form.addEventListener('input', apply);
        form.addEventListener('hierarchy:changed', apply);
        queryInput?.addEventListener('input', apply);

        pagination.addEventListener('click', (event) => {
            const target = event.target;
            if (!(target instanceof HTMLButtonElement)) {
                return;
            }

            const nextPage = Number(target.dataset.page);
            if (!Number.isInteger(nextPage) || nextPage < 1) {
                return;
            }

            state.currentPage = nextPage;
            drawResults();
        });

        apply();
    }

    document.addEventListener('DOMContentLoaded', async () => {
        await loadRemoteFilterData();
        initHierarchyGroups();
        initTagInputs();

        const page = document.body.dataset.page;
        if (page === 'home') {
            initHomePage();
        }
        if (page === 'upload') {
            initUploadPage();
        }
        if (page === 'detail') {
            initNoteDetailPage();
        }
        if (page === 'search') {
            initSearchPage();
        }
    });
})();
