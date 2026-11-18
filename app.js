/**
 * ==============================================================================
 * КГЭУ Расписание — Клиентская часть на Vue.js 3
 * Объединённое расписание: ПМ-1-24 (синий) и ЦК-ИЦС-3 (оранжевый)
 * ==============================================================================
 */

const { createApp, ref, computed, onMounted } = Vue;

// ------------------------------------------------------------------------------
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ И УТИЛИТЫ ДАТ И СТРОК
// ------------------------------------------------------------------------------

/** Текущая дата в формате YYYY-MM-DD */
function getTodayDateStr() {
  const now = new Date();
  const y = now.getFullYear();
  const m = String(now.getMonth() + 1).padStart(2, '0');
  const d = String(now.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

/** Форматирование заголовка дня («Среда — 18.11.2026») */
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

/** Форматирование заголовка недели («Вся неделя — 16.11 – 22.11.2026») */
function formatCustomWeekTitle(startDateStr, endDateStr) {
  if (!startDateStr || !endDateStr) return '';
  const d1 = new Date(startDateStr);
  const d2 = new Date(endDateStr);
  const dd1 = String(d1.getDate()).padStart(2, '0');
  const mm1 = String(d1.getMonth() + 1).padStart(2, '0');
  const dd2 = String(d2.getDate()).padStart(2, '0');
  const mm2 = String(d2.getMonth() + 1).padStart(2, '0');
  const yyyy2 = d2.getFullYear();
  return `Вся неделя — ${dd1}.${mm1} – ${dd2}.${mm2}.${yyyy2}`;
}

/**
 * Очищает название предмета от префиксов (лек, лаб, пр, зач, экз)
 * Например: "лаб Программирование на Python" -> "Программирование на Python"
 */
function cleanDisciplineTitle(rawName) {
  if (!rawName) return '—';
  return String(rawName)
    .trim()
    .replace(/^(лек|лаб|пр|зач|экз)\s+/iu, '')
    .trim();
}

/** Уникальный ключ для идентификации пары в расписании */
function getPairKey(item) {
  if (!item) return '';
  const g = item.groupId || item.groupName || '';
  const dateStr = item.дата ? item.дата.split('T')[0] : '';
  const num = item.номерЗанятия || '';
  const time = (item.начало || '').trim();
  const sub = item.номерПодгруппы || 0;
  const disc = (item.дисциплина || '').trim().toLowerCase();
  return `${g}_${dateStr}_${num}_${time}_${sub}_${disc}`;
}

/** Возвращает массив из 7 дат недели (Пн..Вс) для указанной даты */
function getWeekDates(targetDateStr) {
  if (!targetDateStr) return [];
  const target = new Date(targetDateStr);
  const day = target.getDay(); // 0 (Вс) .. 6 (Сб)
  const diff = (day === 0 ? -6 : 1) - day;
  const monday = new Date(target);
  monday.setDate(target.getDate() + diff);

  const week = [];
  for (let i = 0; i < 7; i++) {
    const d = new Date(monday);
    d.setDate(monday.getDate() + i);
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const dayNum = String(d.getDate()).padStart(2, '0');
    week.push(`${y}-${m}-${dayNum}`);
  }
  return week;
}

/** Проверяет, идет ли пара прямо сейчас */
function isPairCurrent(startIso, endIso) {
  if (!startIso || !endIso) return false;
  const now = new Date();
  const start = new Date(startIso);
  const end = new Date(endIso);
  return now >= start && now <= end;
}

// ------------------------------------------------------------------------------
// ОСНОВНОЕ ПРИЛОЖЕНИЕ VUE 3
// ------------------------------------------------------------------------------
createApp({
  setup() {
    // --------------------------------------------------------------------------
    // 1. РЕАКТИВНОЕ СОСТОЯНИЕ (STATE)
    // --------------------------------------------------------------------------

    // Активный фильтр по группе: 'all' (все группы) | '14493' (ПМ-1-24) | '15006' (ЦК-ИЦС-3)
    const activeGroupFilter = ref('all');

    // Выбранная дата в формате YYYY-MM-DD (по умолчанию — сегодня)
    const selectedDate = ref(getTodayDateStr());

    // Режим отображения: 'day' (один день) или 'week' (вся неделя)
    const currentViewMode = ref('day');

    // Месяц для левого календаря (объект Date)
    const calendarMonth = ref(new Date());

    // База пар расписания, сгруппированная по датам: { 'YYYY-MM-DD': [пара1, пара2] }
    const allPairsMap = ref({});

    // Статус синхронизации и время последнего обновления
    const isSyncing = ref(false);
    const syncTimeText = ref('Загрузка...');

    // Флаг тёмной темы оформления
    const isDark = ref(false);

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

    // Генерация сетки дней для текущего месяца календаря с точками групп
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
        let pairs = allPairsMap.value[dateStr] || [];

        // Если включен фильтр конкретной группы — фильтруем и точки в календаре
        if (activeGroupFilter.value !== 'all') {
          pairs = pairs.filter(p => String(p.groupId) === String(activeGroupFilter.value));
        }

        let hasPm = false;
        let hasCk = false;

        pairs.forEach(p => {
          if (String(p.groupId) === '14493' || p.groupName === 'ПМ-1-24') hasPm = true;
          if (String(p.groupId) === '15006' || p.groupName === 'ЦК-ИЦС-3') hasCk = true;
        });

        days.push({
          isEmpty: false,
          dayNumber: d,
          dateStr: dateStr,
          isSelected: selectedDate.value === dateStr,
          isWeekHighlighted: currentViewMode.value === 'week' && weekDates.includes(dateStr),
          isToday: dateStr === realToday,
          hasPm,
          hasCk
        });
      }

      return days;
    });

    // Список пар, отфильтрованный по выбранной дате/неделе и группе
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

      // Фильтрация по группе (ПМ-1-24 или ЦК-ИЦС-3)
      if (activeGroupFilter.value !== 'all') {
        rawPairs = rawPairs.filter(p => String(p.groupId) === String(activeGroupFilter.value));
      }

      // Дедупликация одинаковых записей пар
      const unique = new Map();
      rawPairs.forEach(p => {
        const k = getPairKey(p);
        if (!unique.has(k)) unique.set(k, p);
      });
      let list = Array.from(unique.values());

      // Сортировка по времени начала
      list.sort((a, b) => {
        const dComp = (a.дата || '').localeCompare(b.дата || '');
        if (dComp !== 0) return dComp;
        return (a.начало || '').localeCompare(b.начало || '');
      });

      return list;
    });

    // --------------------------------------------------------------------------
    // 3. МЕТОДЫ (МГНОВЕННЫЕ ДЕЙСТВИЯ БЕЗ ПЕРЕЗАГРУЗОК)
    // --------------------------------------------------------------------------

    // Выбор дня в календаре: меняет дату и моментально перерисовывает расписание
    function selectCalendarDate(dateStr) {
      selectedDate.value = dateStr;
      currentViewMode.value = 'day';
    }

    // Переключение между режимами «Один день» и «Вся неделя»
    function toggleWeekView() {
      currentViewMode.value = currentViewMode.value === 'week' ? 'day' : 'week';
    }

    // Предыдущий месяц в календаре
    function prevMonth() {
      calendarMonth.value = new Date(
        calendarMonth.value.getFullYear(),
        calendarMonth.value.getMonth() - 1,
        1
      );
    }

    // Следующий месяц в календаре
    function nextMonth() {
      calendarMonth.value = new Date(
        calendarMonth.value.getFullYear(),
        calendarMonth.value.getMonth() + 1,
        1
      );
    }

    // Возврат календаря на сегодняшний день
    function resetToToday() {
      const todayStr = getTodayDateStr();
      selectedDate.value = todayStr;
      calendarMonth.value = new Date();
    }

    // Переключение активной группы (Все / ПМ-1-24 / ЦК-ИЦС-3)
    function selectGroupFilter(groupId) {
      activeGroupFilter.value = String(groupId);
    }

    // Переключение темы (светлая / тёмная)
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

    // --------------------------------------------------------------------------
    // 4. СВЯЗЬ С PHP-БЭКЕНДОМ ЧЕРЕЗ API
    // --------------------------------------------------------------------------

    // Загрузка расписания обеих групп
    async function loadSchedule() {
      isSyncing.value = true;

      // Быстрое чтение локальных кэшей
      try {
        const [c14493, c15006] = await Promise.allSettled([
          fetch('./data/schedule_semester_14493.json').then(r => r.ok ? r.json() : null),
          fetch('./data/schedule_semester_15006.json').then(r => r.ok ? r.json() : null)
        ]);
        const cachedPairs = [];
        if (c14493.status === 'fulfilled' && c14493.value?.data?.rasp) {
          c14493.value.data.rasp.forEach(p => {
            p.groupId = 14493;
            p.groupName = 'ПМ-1-24';
            cachedPairs.push(p);
          });
        }
        if (c15006.status === 'fulfilled' && c15006.value?.data?.rasp) {
          c15006.value.data.rasp.forEach(p => {
            p.groupId = 15006;
            p.groupName = 'ЦК-ИЦС-3';
            cachedPairs.push(p);
          });
        }
        if (cachedPairs.length > 0) {
          applyScheduleItems(cachedPairs);
        }
      } catch (e) {
        console.warn('Локальный кэш:', e);
      }

      // Получаем объединенные данные от PHP сервера (/api/full-schedule)
      try {
        const res = await fetch('/api/full-schedule?idGroup=all');
        if (res.ok) {
          const fullData = await res.json();
          if (fullData?.data?.rasp) {
            applyScheduleItems(fullData.data.rasp);
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
        const res = await fetch('/api/full-schedule?idGroup=all&refresh=1');
        if (res.ok) {
          const data = await res.json();
          if (data?.data?.rasp) {
            applyScheduleItems(data.data.rasp);
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
    // 5. ИНИЦИАЛИЗАЦИЯ ПРИ ЗАПУСКЕ (MOUNTED)
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

      // Загружаем объединенное расписание
      loadSchedule();
    });

    // --------------------------------------------------------------------------
    // 6. ВОЗВРАТ ПЕРЕМЕННЫХ И МЕТОДОВ В ШАБЛОН HTML
    // --------------------------------------------------------------------------
    return {
      activeGroupFilter,
      selectedDate,
      currentViewMode,
      calendarMonth,
      calendarMonthTitle,
      calendarDays,
      currentViewTitle,
      displayedPairs,
      isSyncing,
      syncTimeText,
      isDark,
      selectCalendarDate,
      toggleWeekView,
      prevMonth,
      nextMonth,
      resetToToday,
      selectGroupFilter,
      toggleTheme,
      manualRefresh,
      cleanDisciplineTitle,
      isPairCurrent,
      getPairKey
    };
  }
}).mount('#app');
