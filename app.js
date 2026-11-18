/**
 * ==============================================================================
 * КГЭУ Расписание — Клиентская часть на Vue.js 3
 * ==============================================================================
 * 
 * Архитектура:
 *  - Vue 3 управляет всем интерфейсом реактивно: когда меняются данные (state),
 *    Vue сам моментально обновляет нужные элементы в браузере без перезагрузки страниц.
 *  - Серверная часть (PHP) поставляет данные через REST API (/api/full-schedule, /api/todo).
 */

const { createApp, ref, computed, onMounted } = Vue;

createApp({
  setup() {
    // --------------------------------------------------------------------------
    // 1. РЕАКТИВНОЕ СОСТОЯНИЕ (STATE)
    // --------------------------------------------------------------------------
    
    // Текущая группа студентов КГЭУ (14493 = ПМ-1-24)
    const groupId = ref(14493);

    // Выбранная дата в формате YYYY-MM-DD (по умолчанию — сегодня)
    const selectedDate = ref(getTodayDateStr());

    // Режим отображения: 'day' (один день) или 'week' (вся неделя)
    const currentViewMode = ref('day');

    // Активный фильтр по типу пары: 'all', 'lecture', 'lab', 'practice'
    const activeTypeFilter = ref('all');

    // Месяц для левого календаря (объект Date)
    const calendarMonth = ref(new Date());

    // База пар расписания, сгруппированная по датам: { 'YYYY-MM-DD': [пара1, пара2] }
    const allPairsMap = ref({});

    // Общая информация о группе и семестре от API КГЭУ
    const scheduleInfo = ref(null);

    // Статус синхронизации и время последнего обновления
    const isSyncing = ref(false);
    const syncTimeText = ref('Загрузка...');

    // Флаг тёмной темы оформления
    const isDark = ref(false);

    // Открыта ли плашка с фильтрами
    const isFiltersOpen = ref(false);

    // Состояние заметок и чекбоксов (синхронизируется с сервером PHP в todo_state.json)
    const todoData = ref({
      checkboxes: {},     // { 'ИмяПредмета_номерЛабы': true/false }
      customNotes: [],    // [ { id, pairKey, date, text, completed } ]
      editedDefaults: {}, // { 'ИмяПредмета_номерЛабы': 'Своё название' }
      deletedDefaults: [] // [ 'ИмяПредмета_номерЛабы' ]
    });

    // Состояние модального окна добавления/редактирования заметок
    const modal = ref({
      isOpen: false,
      title: 'Заметка / задача',
      mode: 'add',        // 'add' | 'edit'
      targetType: 'pair', // 'pair' | 'day' | 'default_lab'
      id: '',
      pairKey: '',
      date: '',
      text: ''
    });

    // Хронология лабораторных работ семестра (по 1 лабе на сдвоенные пары)
    const SUBJECT_LAB_TIMELINE = {
      'Исследование операций': {
        unit: 'Лабораторная работа',
        dates: ['2026-09-03', '2026-09-16', '2026-09-30', '2026-10-14', '2026-10-28', '2026-11-11', '2026-11-25', '2026-12-09']
      },
      'Маркетинг ИТ-продуктов': {
        unit: 'Лабораторная работа',
        dates: ['2026-09-10', '2026-09-24', '2026-10-08', '2026-10-22', '2026-11-05', '2026-11-19', '2026-12-03', '2026-12-17']
      },
      'Сетевое планирование и управление ИТ-проектами': {
        unit: 'Лабораторная работа',
        dates: ['2026-09-11', '2026-09-25', '2026-10-09', '2026-10-23', '2026-11-06', '2026-11-20', '2026-12-04', '2026-12-18']
      },
      'Интерфейс программных продуктов': {
        unit: 'Лабораторная работа',
        dates: ['2026-09-12', '2026-09-26', '2026-10-10', '2026-10-24', '2026-11-07', '2026-11-21', '2026-12-05', '2026-12-19']
      },
      'Web-программирование': {
        unit: 'Лабораторная работа',
        dates: ['2026-09-15', '2026-09-29', '2026-10-13', '2026-10-27', '2026-11-10', '2026-11-24', '2026-12-08']
      },
      'Статистика': {
        unit: 'Лабораторная работа',
        dates: ['2026-09-15', '2026-09-29', '2026-10-13', '2026-10-27', '2026-11-10', '2026-11-24', '2026-12-08']
      },
      'Оценка эффективности ИТ-проектов': {
        unit: 'Кейс',
        dates: ['2026-09-18', '2026-10-02', '2026-10-16', '2026-10-30', '2026-11-13', '2026-11-27', '2026-12-11']
      }
    };

    // --------------------------------------------------------------------------
    // 2. ВЫЧИСЛЯЕМЫЕ СВОЙСТВА (COMPUTED)
    // --------------------------------------------------------------------------

    // Название текущего месяца для шапки календаря (например: «Ноябрь 2026»)
    const calendarMonthTitle = computed(() => {
      return calendarMonth.value.toLocaleDateString('ru-RU', {
        month: 'long',
        year: 'numeric'
      }).replace(' г.', '');
    });

    // Заголовок текущего дня без обрезания в "..." (например: «Среда — 18.11.2026»)
    const currentViewTitle = computed(() => {
      if (currentViewMode.value === 'day') {
        return formatFullDayTitle(selectedDate.value);
      } else {
        const week = getWeekDates(selectedDate.value);
        return formatCustomWeekTitle(week[0], week[6]);
      }
    });

    // Текст на кнопке фильтра в зависимости от выбранного типа
    const currentFilterLabel = computed(() => {
      const labels = {
        all: 'Фильтры',
        lecture: 'Лекции',
        lab: 'Лабораторные',
        practice: 'Практики'
      };
      return labels[activeTypeFilter.value] || 'Фильтры';
    });

    // Генерация сетки дней для текущего месяца календаря
    const calendarDays = computed(() => {
      const year = calendarMonth.value.getFullYear();
      const month = calendarMonth.value.getMonth();

      const firstDay = new Date(year, month, 1);
      const lastDay = new Date(year, month + 1, 0);

      let startDayOfWeek = firstDay.getDay(); // 0 (Вс) .. 6 (Сб)
      if (startDayOfWeek === 0) startDayOfWeek = 7; // Делаем 1 (Пн) .. 7 (Вс)

      const totalDays = lastDay.getDate();
      const realToday = getTodayDateStr();
      const weekDates = currentViewMode.value === 'week' ? getWeekDates(selectedDate.value) : [];

      const days = [];

      // Пустые ячейки в начале сетки до 1-го числа
      for (let i = 1; i < startDayOfWeek; i++) {
        days.push({ isEmpty: true, key: `empty-${i}` });
      }

      // Числа месяца
      for (let d = 1; d <= totalDays; d++) {
        const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
        const pairs = allPairsMap.value[dateStr] || [];

        // Собираем типы занятий для цветных точек
        const typeSet = new Set();
        pairs.forEach(p => {
          const disc = parseDiscipline(p.дисциплина);
          typeSet.add(disc.typeKey);
        });

        days.push({
          isEmpty: false,
          dayNumber: d,
          dateStr: dateStr,
          isSelected: selectedDate.value === dateStr,
          isWeekHighlighted: currentViewMode.value === 'week' && weekDates.includes(dateStr),
          isToday: dateStr === realToday,
          hasLecture: typeSet.has('lecture'),
          hasLab: typeSet.has('lab'),
          hasPractice: typeSet.has('practice'),
          hasExam: typeSet.has('exam')
        });
      }

      return days;
    });

    // Список пар, отфильтрованный по выбранной дате/неделе и типу
    const displayedPairs = computed(() => {
      let rawPairs = [];

      if (currentViewMode.value === 'day') {
        rawPairs = allPairsMap.value[selectedDate.value] || [];
      } else {
        const week = getWeekDates(selectedDate.value);
        week.forEach(d => {
          const p = allPairsMap.value[d] || [];
          rawPairs.push(...p);
        });
      }

      // Дедупликация одинаковых записей пар
      const unique = new Map();
      rawPairs.forEach(p => {
        const k = getPairKey(p);
        if (!unique.has(k)) unique.set(k, p);
      });
      let list = Array.from(unique.values());

      // Фильтрация по типу (Лекция, Лабораторная, Практика)
      if (activeTypeFilter.value !== 'all') {
        list = list.filter(p => {
          const disc = parseDiscipline(p.дисциплина);
          return disc.typeKey === activeTypeFilter.value;
        });
      }

      // Сортировка по времени начала
      list.sort((a, b) => {
        const dComp = (a.дата || '').localeCompare(b.дата || '');
        if (dComp !== 0) return dComp;
        return (a.начало || '').localeCompare(b.начало || '');
      });

      return list;
    });

    // Заметки на выбранный день (общие, не привязанные к конкретной паре)
    const dayGeneralNotes = computed(() => {
      if (currentViewMode.value !== 'day') return [];
      return (todoData.value.customNotes || []).filter(
        n => n.date === selectedDate.value && !n.pairKey
      );
    });

    // --------------------------------------------------------------------------
    // 3. МЕТОДЫ (МГНОВЕННЫЕ ДЕЙСТВИЯ БЕЗ ПЕРЕЗАГРУЗОК)
    // --------------------------------------------------------------------------

    // Выбор дня в календаре: меняет дату и моментально перерисовывает расписание
    function selectCalendarDate(dateStr) {
      selectedDate.value = dateStr;
      currentViewMode.value = 'day';
    }

    // Переключение режима «Один день» / «Вся неделя»
    function toggleWeekView() {
      currentViewMode.value = currentViewMode.value === 'week' ? 'day' : 'week';
    }

    // Навигация по месяцам календаря
    function prevMonth() {
      calendarMonth.value = new Date(
        calendarMonth.value.getFullYear(),
        calendarMonth.value.getMonth() - 1,
        1
      );
    }

    function nextMonth() {
      calendarMonth.value = new Date(
        calendarMonth.value.getFullYear(),
        calendarMonth.value.getMonth() + 1,
        1
      );
    }

    function resetToToday() {
      const today = getTodayDateStr();
      selectedDate.value = today;
      calendarMonth.value = new Date();
      currentViewMode.value = 'day';
    }

    // Открытие/закрытие плашки фильтра
    function toggleFiltersDropdown() {
      isFiltersOpen.value = !isFiltersOpen.value;
    }

    function selectFilter(typeKey) {
      activeTypeFilter.value = typeKey;
      isFiltersOpen.value = false;
    }

    // Переключение темной/светлой темы
    function toggleTheme() {
      isDark.value = !isDark.value;
      if (isDark.value) {
        document.documentElement.classList.add('dark');
        localStorage.setItem('theme', 'dark');
      } else {
        document.documentElement.classList.remove('dark');
        localStorage.setItem('theme', 'light');
      }
    }

    // Переключение чекбокса стандартной лабораторной работы
    function toggleTodoCheckbox(todoKey, isChecked) {
      if (!todoData.value.checkboxes) todoData.value.checkboxes = {};
      todoData.value.checkboxes[todoKey] = isChecked;
      saveTodoDataOnServer();
    }

    // Переключение чекбокса добавленной заметки
    function toggleCustomNoteCheckbox(noteId, isChecked) {
      const note = (todoData.value.customNotes || []).find(n => n.id === noteId);
      if (note) {
        note.completed = isChecked;
        saveTodoDataOnServer();
      }
    }

    // Удаление кастомной заметки
    function deleteCustomNote(noteId) {
      todoData.value.customNotes = (todoData.value.customNotes || []).filter(
        n => n.id !== noteId
      );
      saveTodoDataOnServer();
    }

    // Удаление (скрытие) стандартной лабораторной
    function deleteDefaultLab(todoKey) {
      if (!todoData.value.deletedDefaults) todoData.value.deletedDefaults = [];
      if (!todoData.value.deletedDefaults.includes(todoKey)) {
        todoData.value.deletedDefaults.push(todoKey);
      }
      saveTodoDataOnServer();
    }

    // Открытие модального окна для добавления задачи к паре
    function openAddPairNoteModal(pairKey, pairDate) {
      modal.value = {
        isOpen: true,
        title: 'Добавить задачу к занятию',
        mode: 'add',
        targetType: 'pair',
        id: '',
        pairKey: pairKey,
        date: pairDate || selectedDate.value,
        text: ''
      };
    }

    // Открытие модального окна для общей заметки на день
    function openAddDayNoteModal() {
      modal.value = {
        isOpen: true,
        title: 'Заметка на день',
        mode: 'add',
        targetType: 'day',
        id: '',
        pairKey: '',
        date: selectedDate.value,
        text: ''
      };
    }

    // Открытие модального окна для редактирования кастомной заметки
    function openEditCustomNoteModal(note) {
      modal.value = {
        isOpen: true,
        title: 'Редактировать заметку',
        mode: 'edit',
        targetType: 'custom',
        id: note.id,
        pairKey: note.pairKey || '',
        date: note.date || selectedDate.value,
        text: cleanTaskTitle(note.text)
      };
    }

    // Открытие модального окна для переименования стандартной лабы
    function openEditDefaultLabModal(todoKey, currentLabel) {
      modal.value = {
        isOpen: true,
        title: 'Редактировать название работы',
        mode: 'edit',
        targetType: 'default_lab',
        id: todoKey,
        pairKey: '',
        date: '',
        text: cleanTaskTitle(todoData.value.editedDefaults?.[todoKey] || currentLabel)
      };
    }

    function closeModal() {
      modal.value.isOpen = false;
    }

    // Сохранение заметки из модального окна
    function handleModalSubmit() {
      const text = cleanTaskTitle(modal.value.text);
      if (!text) return;

      if (modal.value.mode === 'add') {
        const newNote = {
          id: 'note_' + Date.now() + '_' + Math.random().toString(36).substring(2, 6),
          pairKey: modal.value.targetType === 'pair' ? modal.value.pairKey : null,
          date: modal.value.date || selectedDate.value,
          text: text,
          completed: false
        };
        if (!todoData.value.customNotes) todoData.value.customNotes = [];
        todoData.value.customNotes.push(newNote);
      } else if (modal.value.mode === 'edit') {
        if (modal.value.targetType === 'custom') {
          const note = (todoData.value.customNotes || []).find(n => n.id === modal.value.id);
          if (note) note.text = text;
        } else if (modal.value.targetType === 'default_lab') {
          if (!todoData.value.editedDefaults) todoData.value.editedDefaults = {};
          todoData.value.editedDefaults[modal.value.id] = text;
        }
      }

      saveTodoDataOnServer();
      closeModal();
    }

    // Быстрое добавление инлайн-заметки на день
    function addQuickDayNote(event) {
      const input = event.target.elements.quickText;
      const val = cleanTaskTitle(input.value);
      if (!val) return;

      todoData.value.customNotes.push({
        id: 'note_' + Date.now() + '_' + Math.random().toString(36).substring(2, 6),
        pairKey: null,
        date: selectedDate.value,
        text: val,
        completed: false
      });
      input.value = '';
      saveTodoDataOnServer();
    }

    // --------------------------------------------------------------------------
    // 4. СВЯЗЬ С PHP-БЭКЕНДОМ ЧЕРЕЗ API
    // --------------------------------------------------------------------------

    // Загрузка состояния заметок с сервера PHP
    async function loadTodoDataFromServer() {
      const cached = localStorage.getItem('kgeu_todo_data_v4');
      if (cached) {
        try {
          todoData.value = JSON.parse(cached);
        } catch {}
      }

      try {
        const res = await fetch('/api/todo');
        if (res.ok) {
          const data = await res.json();
          if (data && typeof data === 'object') {
            todoData.value = {
              checkboxes: data.checkboxes || {},
              customNotes: data.customNotes || [],
              editedDefaults: data.editedDefaults || {},
              deletedDefaults: data.deletedDefaults || []
            };
            localStorage.setItem('kgeu_todo_data_v4', JSON.stringify(todoData.value));
          }
        }
      } catch (e) {
        console.warn('Ошибка загрузки заметок:', e);
      }
    }

    // Фоновое сохранение заметок на PHP-сервер
    async function saveTodoDataOnServer() {
      localStorage.setItem('kgeu_todo_data_v4', JSON.stringify(todoData.value));
      try {
        await fetch('/api/todo', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(todoData.value)
        });
      } catch (e) {
        console.warn('Ошибка сохранения заметок на сервер:', e);
      }
    }

    // Загрузка полного расписания на весь семестр
    async function loadSchedule() {
      isSyncing.value = true;

      // 1. Быстро читаем локальный кэш
      const localCandidates = [
        `./data/schedule_semester_${groupId.value}.json`,
        './data/schedule_semester_cache.json',
        './data/schedule_sample.json'
      ];
      for (const p of localCandidates) {
        try {
          const res = await fetch(p);
          if (res.ok) {
            const data = await res.json();
            if (data?.data?.rasp) {
              applyScheduleItems(data.data.rasp);
              if (data.data.info) scheduleInfo.value = data.data.info;
              break;
            }
          }
        } catch {}
      }

      // 2. Получаем свежее расписание от PHP сервера (/api/full-schedule)
      try {
        const res = await fetch(`/api/full-schedule?idGroup=${groupId.value}`);
        if (res.ok) {
          const fullData = await res.json();
          if (fullData?.data?.rasp) {
            applyScheduleItems(fullData.data.rasp);
            if (fullData.data.info) scheduleInfo.value = fullData.data.info;
          }
        }
      } catch (e) {
        console.warn('Сетевой запрос расписания:', e);
      } finally {
        isSyncing.value = false;
        const now = new Date();
        syncTimeText.value = `Обновлено в ${now.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })}`;
      }
    }

    // Ручное принудительное обновление расписания
    async function manualRefresh() {
      isSyncing.value = true;
      try {
        const res = await fetch(`/api/full-schedule?idGroup=${groupId.value}&refresh=1`);
        if (res.ok) {
          const data = await res.json();
          if (data?.data?.rasp) {
            applyScheduleItems(data.data.rasp);
            if (data.data.info) scheduleInfo.value = data.data.info;
          }
        }
      } catch (e) {
        console.warn('Ошибка принудительного обновления:', e);
      } finally {
        isSyncing.value = false;
        const now = new Date();
        syncTimeText.value = `Обновлено в ${now.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })}`;
      }
    }

    // Раскладываем пары по датам
    function applyScheduleItems(items) {
      if (!Array.isArray(items)) return;
      const map = {};
      items.forEach(it => {
        if (!it.дата) return;
        const d = it.дата.split('T')[0];
        if (!map[d]) map[d] = [];
        map[d].push(it);
      });
      allPairsMap.value = map;
    }

    // --------------------------------------------------------------------------
    // 5. ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ДЛЯ КАРТОЧЕК
    // --------------------------------------------------------------------------

    function getLabInfo(subjectTitle, dateStr) {
      let matched = null;
      const cleanTitle = (subjectTitle || '').toLowerCase();

      for (const k of Object.keys(SUBJECT_LAB_TIMELINE)) {
        const kLow = k.toLowerCase();
        if (cleanTitle.includes(kLow) || kLow.includes(cleanTitle)) {
          matched = k;
          break;
        }
        if (cleanTitle.includes('сетевое') && kLow.includes('сетевое')) { matched = k; break; }
        if (cleanTitle.includes('маркетинг') && kLow.includes('маркетинг')) { matched = k; break; }
        if (cleanTitle.includes('интерфейс') && kLow.includes('интерфейс')) { matched = k; break; }
        if (cleanTitle.includes('web') && kLow.includes('web')) { matched = k; break; }
        if (cleanTitle.includes('оценка') && kLow.includes('оценка')) { matched = k; break; }
        if (cleanTitle.includes('исследование') && kLow.includes('исследование')) { matched = k; break; }
        if (cleanTitle.includes('статистик') && kLow.includes('статистик')) { matched = k; break; }
      }

      if (!matched) return null;

      const conf = SUBJECT_LAB_TIMELINE[matched];
      const dates = conf.dates || [];
      const unit = conf.unit || 'Лабораторная работа';

      let labNum = 1;
      const idx = dates.indexOf(dateStr);
      if (idx >= 0) {
        labNum = idx + 1;
      } else {
        let count = 0;
        for (const d of dates) {
          if (d <= dateStr) count++;
        }
        labNum = count > 0 ? count : 1;
      }

      const todoKey = `${matched}_${labNum}`;
      const isChecked = !!(todoData.value.checkboxes && todoData.value.checkboxes[todoKey]);
      const customTitle = todoData.value.editedDefaults?.[todoKey];

      // Полное отсутствие префикса TODO:
      const label = cleanTaskTitle(customTitle || `${unit} №${labNum}`);

      return {
        todoKey,
        label,
        isChecked,
        isDeleted: (todoData.value.deletedDefaults || []).includes(todoKey)
      };
    }

    function getCustomTasksForPair(item) {
      const key = getPairKey(item);
      return (todoData.value.customNotes || []).filter(n => n.pairKey === key);
    }

    // --------------------------------------------------------------------------
    // 6. ИНИЦИАЛИЗАЦИЯ ПРИ ЗАПУСКЕ (MOUNTED)
    // --------------------------------------------------------------------------
    onMounted(() => {
      // Восстанавливаем тему (Dark / Light)
      const savedTheme = localStorage.getItem('theme');
      if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        isDark.value = true;
        document.documentElement.classList.add('dark');
      } else {
        isDark.value = false;
        document.documentElement.classList.remove('dark');
      }

      // Закрытие выпадающего меню фильтров при клике вне него
      document.addEventListener('click', (e) => {
        const container = document.getElementById('filters-container');
        if (container && !container.contains(e.target)) {
          isFiltersOpen.value = false;
        }
      });

      // Загружаем заметки и расписание
      loadTodoDataFromServer();
      loadSchedule();
    });

    return {
      groupId,
      selectedDate,
      currentViewMode,
      activeTypeFilter,
      calendarMonth,
      calendarMonthTitle,
      calendarDays,
      currentViewTitle,
      currentFilterLabel,
      displayedPairs,
      dayGeneralNotes,
      isSyncing,
      syncTimeText,
      isDark,
      isFiltersOpen,
      modal,
      selectCalendarDate,
      toggleWeekView,
      prevMonth,
      nextMonth,
      resetToToday,
      toggleFiltersDropdown,
      selectFilter,
      toggleTheme,
      toggleTodoCheckbox,
      toggleCustomNoteCheckbox,
      deleteCustomNote,
      deleteDefaultLab,
      openAddPairNoteModal,
      openAddDayNoteModal,
      openEditCustomNoteModal,
      openEditDefaultLabModal,
      closeModal,
      handleModalSubmit,
      addQuickDayNote,
      manualRefresh,
      parseDiscipline,
      isPairCurrent,
      getLabInfo,
      getCustomTasksForPair,
      cleanTaskTitle,
      getPairKey
    };
  }
}).mount('#app');

// ------------------------------------------------------------------------------
// ГЛОБАЛЬНЫЕ УТИЛИТЫ ДАТ И СТРОК
// ------------------------------------------------------------------------------
function getTodayDateStr() {
  const now = new Date();
  const y = now.getFullYear();
  const m = String(now.getMonth() + 1).padStart(2, '0');
  const d = String(now.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

function formatFullDayTitle(dateStr) {
  if (!dateStr) return '';
  const days = ['Воскресенье', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота'];
  const dt = new Date(dateStr);
  const dayName = days[dt.getDay()];
  const dd = String(dt.getDate()).padStart(2, '0');
  const mm = String(dt.getMonth() + 1).padStart(2, '0');
  const yyyy = dt.getFullYear();
  return `${dayName} — ${dd}.${mm}.${yyyy}`;
}

function formatCustomWeekTitle(startDateStr, endDateStr) {
  const d1 = new Date(startDateStr);
  const d2 = new Date(endDateStr);
  const dd1 = String(d1.getDate()).padStart(2, '0');
  const mm1 = String(d1.getMonth() + 1).padStart(2, '0');
  const dd2 = String(d2.getDate()).padStart(2, '0');
  const mm2 = String(d2.getMonth() + 1).padStart(2, '0');
  const yyyy2 = d2.getFullYear();
  return `Вся неделя — ${dd1}.${mm1} – ${dd2}.${mm2}.${yyyy2}`;
}

function cleanTaskTitle(title) {
  if (!title) return '';
  return String(title).replace(/^\s*TODO:\s*/iu, '').trim();
}
