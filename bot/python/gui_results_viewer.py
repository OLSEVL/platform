# -*- coding: utf-8 -*-
"""
Графический интерфейс для отображения результатов сканирования активов
Использует tkinter (встроенная библиотека Python)
"""
import tkinter as tk
from tkinter import ttk, scrolledtext, messagebox
from typing import Dict, List, Optional, Any
from datetime import datetime

# Импорт matplotlib с обработкой ошибок
# Импортируем matplotlib лениво (lazy import) - только при необходимости
MATPLOTLIB_AVAILABLE = False
_matplotlib_imported = False
_Figure = None
_FigureCanvasTkAgg = None
_Rectangle = None
_Patch = None

def _import_matplotlib():
    """Ленивый импорт matplotlib - вызывается только при необходимости"""
    global MATPLOTLIB_AVAILABLE, _matplotlib_imported, _Figure, _FigureCanvasTkAgg, _Rectangle, _Patch
    
    if _matplotlib_imported:
        return MATPLOTLIB_AVAILABLE
    
    _matplotlib_imported = True
    
    # Сохраняем информацию об ошибке для диагностики
    last_error = None
    
    try:
        import matplotlib
        # Устанавливаем backend только если tkinter уже импортирован
        try:
            matplotlib.use('TkAgg', force=True)  # Используем force=True для принудительной установки backend
        except Exception:
            # Если не удалось установить TkAgg, пробуем без явного указания backend
            try:
                matplotlib.use('TkAgg')
            except:
                pass
        
        from matplotlib.figure import Figure
        from matplotlib.backends.backend_tkagg import FigureCanvasTkAgg
        from matplotlib.patches import Rectangle, Patch
        
        _Figure = Figure
        _FigureCanvasTkAgg = FigureCanvasTkAgg
        _Rectangle = Rectangle
        _Patch = Patch
        MATPLOTLIB_AVAILABLE = True
        return True
    except (ImportError, ModuleNotFoundError) as e:
        last_error = e
        MATPLOTLIB_AVAILABLE = False
        return False
    except Exception as e:
        last_error = e
        # Для других ошибок пробуем импортировать без установки backend
        try:
            import matplotlib
            # Пробуем установить backend без force
            try:
                matplotlib.use('TkAgg')
            except:
                pass
            
            from matplotlib.figure import Figure
            from matplotlib.backends.backend_tkagg import FigureCanvasTkAgg
            from matplotlib.patches import Rectangle, Patch
            
            _Figure = Figure
            _FigureCanvasTkAgg = FigureCanvasTkAgg
            _Rectangle = Rectangle
            _Patch = Patch
            MATPLOTLIB_AVAILABLE = True
            return True
        except Exception as import_error:
            last_error = import_error
            MATPLOTLIB_AVAILABLE = False
            return False


class AssetScannerGUI:
    """Графический интерфейс для отображения результатов сканирования"""
    
    def __init__(self, root):
        self.root = root
        self.root.title("Asset Scanner - Результаты сканирования")
        self.root.geometry("1500x950")
        
        # Создаем notebook для вкладок
        self.notebook = ttk.Notebook(root)
        self.notebook.pack(fill=tk.BOTH, expand=True, padx=5, pady=5)
        
        # Вкладки
        self.payout_tab = ttk.Frame(self.notebook)
        self.results_tab = ttk.Frame(self.notebook)
        self.websocket_tab = ttk.Frame(self.notebook)
        self.logs_tab = ttk.Frame(self.notebook)
        
        self.notebook.add(self.payout_tab, text="Payout таблица")
        self.notebook.add(self.results_tab, text="Результаты анализа")
        self.notebook.add(self.websocket_tab, text="WebSocket активы")
        self.notebook.add(self.logs_tab, text="Логи")
        
        # Инициализируем вкладки
        self._setup_payout_tab()
        self._setup_results_tab()
        self._setup_websocket_tab()
        self._setup_logs_tab()
        
        # Данные
        self.payout_data = []
        self.results_data = []
        self.websocket_data = []
        self.logs_text = ""
    
    def _setup_payout_tab(self):
        """Настройка вкладки с payout"""
        # Панель инструментов
        toolbar = ttk.Frame(self.payout_tab)
        toolbar.pack(fill=tk.X, padx=5, pady=5)
        
        ttk.Label(toolbar, text="Фильтр по категории:").pack(side=tk.LEFT, padx=5)
        self.category_filter = ttk.Combobox(toolbar, values=["Все", "Валюты", "Криптовалюты", "Сырьевые товары", "Акции", "Индексы", "Другие"])
        self.category_filter.set("Все")
        self.category_filter.pack(side=tk.LEFT, padx=5)
        self.category_filter.bind("<<ComboboxSelected>>", lambda e: self._update_payout_table())
        
        ttk.Label(toolbar, text="Мин. payout:").pack(side=tk.LEFT, padx=5)
        self.min_payout_var = tk.StringVar(value="0")
        ttk.Entry(toolbar, textvariable=self.min_payout_var, width=10).pack(side=tk.LEFT, padx=5)
        ttk.Button(toolbar, text="Применить", command=self._update_payout_table).pack(side=tk.LEFT, padx=5)
        
        # Таблица
        tree_frame = ttk.Frame(self.payout_tab)
        tree_frame.pack(fill=tk.BOTH, expand=True, padx=5, pady=5)
        
        scrollbar_y = ttk.Scrollbar(tree_frame, orient=tk.VERTICAL)
        scrollbar_x = ttk.Scrollbar(tree_frame, orient=tk.HORIZONTAL)
        
        self.payout_tree = ttk.Treeview(
            tree_frame,
            columns=("category", "asset", "payout", "status"),
            show="headings",
            yscrollcommand=scrollbar_y.set,
            xscrollcommand=scrollbar_x.set
        )
        
        scrollbar_y.config(command=self.payout_tree.yview)
        scrollbar_x.config(command=self.payout_tree.xview)
        
        self.payout_tree.heading("category", text="Категория")
        self.payout_tree.heading("asset", text="Актив")
        self.payout_tree.heading("payout", text="Payout %")
        self.payout_tree.heading("status", text="Статус")
        
        self.payout_tree.column("category", width=150)
        self.payout_tree.column("asset", width=300)
        self.payout_tree.column("payout", width=100, anchor=tk.CENTER)
        self.payout_tree.column("status", width=120, anchor=tk.CENTER)
        
        self.payout_tree.pack(side=tk.LEFT, fill=tk.BOTH, expand=True)
        scrollbar_y.pack(side=tk.RIGHT, fill=tk.Y)
        scrollbar_x.pack(side=tk.BOTTOM, fill=tk.X)
        
        # Статистика
        self.payout_stats = ttk.Label(self.payout_tab, text="Статистика: Загрузка...")
        self.payout_stats.pack(side=tk.BOTTOM, fill=tk.X, padx=5, pady=5)
    
    # Вкладка DOM ↔ WebSocket удалена - больше не используется
    def _setup_results_tab(self):
        """Настройка вкладки с результатами анализа"""
        # Панель инструментов
        toolbar = ttk.Frame(self.results_tab)
        toolbar.pack(fill=tk.X, padx=5, pady=5)
        
        ttk.Label(toolbar, text="Фильтр по действию:").pack(side=tk.LEFT, padx=5)
        self.action_filter = ttk.Combobox(toolbar, values=["Все", "CALL", "PUT", "WAIT"])
        self.action_filter.set("Все")
        self.action_filter.pack(side=tk.LEFT, padx=5)
        self.action_filter.bind("<<ComboboxSelected>>", lambda e: self._update_results_table())
        
        # Таблица
        tree_frame = ttk.Frame(self.results_tab)
        tree_frame.pack(fill=tk.BOTH, expand=True, padx=5, pady=5)
        
        scrollbar_y = ttk.Scrollbar(tree_frame, orient=tk.VERTICAL)
        scrollbar_x = ttk.Scrollbar(tree_frame, orient=tk.HORIZONTAL)
        
        self.results_tree = ttk.Treeview(
            tree_frame,
            columns=("asset", "payout", "action", "candle_colors", "status"),
            show="headings",
            yscrollcommand=scrollbar_y.set,
            xscrollcommand=scrollbar_x.set
        )
        
        scrollbar_y.config(command=self.results_tree.yview)
        scrollbar_x.config(command=self.results_tree.xview)
        
        self.results_tree.heading("asset", text="Актив")
        self.results_tree.heading("payout", text="Payout %")
        self.results_tree.heading("action", text="Действие")
        self.results_tree.heading("candle_colors", text="Последние 6 свечей")
        self.results_tree.heading("status", text="Статус")
        
        self.results_tree.column("asset", width=250)
        self.results_tree.column("payout", width=100, anchor=tk.CENTER)
        self.results_tree.column("action", width=120, anchor=tk.CENTER)
        self.results_tree.column("candle_colors", width=250)
        self.results_tree.column("status", width=150)
        
        self.results_tree.pack(side=tk.LEFT, fill=tk.BOTH, expand=True)
        scrollbar_y.pack(side=tk.RIGHT, fill=tk.Y)
        scrollbar_x.pack(side=tk.BOTTOM, fill=tk.X)
        
        # Привязываем двойной клик для открытия графика
        self.results_tree.bind("<Double-1>", self._on_result_double_click)
        
        # Статистика
        self.results_stats = ttk.Label(self.results_tab, text="Результаты: Загрузка...")
        self.results_stats.pack(side=tk.BOTTOM, fill=tk.X, padx=5, pady=5)
    
    def _setup_websocket_tab(self):
        """Настройка вкладки с WebSocket активами"""
        # Панель инструментов
        toolbar = ttk.Frame(self.websocket_tab)
        toolbar.pack(fill=tk.X, padx=5, pady=5)
        
        ttk.Label(toolbar, text="Фильтр по категории:").pack(side=tk.LEFT, padx=5)
        self.websocket_category_filter = ttk.Combobox(toolbar, values=["Все", "Валюты", "Криптовалюты", "Сырьевые товары", "Акции", "Индексы", "Другие"])
        self.websocket_category_filter.set("Все")
        self.websocket_category_filter.pack(side=tk.LEFT, padx=5)
        self.websocket_category_filter.bind("<<ComboboxSelected>>", lambda e: self._update_websocket_table())
        
        ttk.Label(toolbar, text="Поиск:").pack(side=tk.LEFT, padx=5)
        self.websocket_search_var = tk.StringVar()
        search_entry = ttk.Entry(toolbar, textvariable=self.websocket_search_var, width=30)
        search_entry.pack(side=tk.LEFT, padx=5)
        search_entry.bind("<KeyRelease>", lambda e: self._update_websocket_table())
        
        ttk.Button(toolbar, text="Очистить", command=lambda: [self.websocket_search_var.set(""), self._update_websocket_table()]).pack(side=tk.LEFT, padx=5)
        
        # Таблица
        tree_frame = ttk.Frame(self.websocket_tab)
        tree_frame.pack(fill=tk.BOTH, expand=True, padx=5, pady=5)
        
        scrollbar_y = ttk.Scrollbar(tree_frame, orient=tk.VERTICAL)
        scrollbar_x = ttk.Scrollbar(tree_frame, orient=tk.HORIZONTAL)
        
        self.websocket_tree = ttk.Treeview(
            tree_frame,
            columns=("asset_id", "asset_name", "category", "has_payout"),
            show="headings",
            yscrollcommand=scrollbar_y.set,
            xscrollcommand=scrollbar_x.set
        )
        
        scrollbar_y.config(command=self.websocket_tree.yview)
        scrollbar_x.config(command=self.websocket_tree.xview)
        
        self.websocket_tree.heading("asset_id", text="ID")
        self.websocket_tree.heading("asset_name", text="Название актива")
        self.websocket_tree.heading("category", text="Категория")
        self.websocket_tree.heading("has_payout", text="Есть Payout")
        
        self.websocket_tree.column("asset_id", width=100, anchor=tk.CENTER)
        self.websocket_tree.column("asset_name", width=300)
        self.websocket_tree.column("category", width=150)
        self.websocket_tree.column("has_payout", width=120, anchor=tk.CENTER)
        
        self.websocket_tree.pack(side=tk.LEFT, fill=tk.BOTH, expand=True)
        scrollbar_y.pack(side=tk.RIGHT, fill=tk.Y)
        scrollbar_x.pack(side=tk.BOTTOM, fill=tk.X)
        
        # Статистика
        self.websocket_stats = ttk.Label(self.websocket_tab, text="Статистика: Загрузка...")
        self.websocket_stats.pack(side=tk.BOTTOM, fill=tk.X, padx=5, pady=5)
    
    def _setup_logs_tab(self):
        """Настройка вкладки с логами"""
        self.logs_text_widget = scrolledtext.ScrolledText(
            self.logs_tab,
            wrap=tk.WORD,
            font=("Consolas", 9),
            bg="#1e1e1e",
            fg="#d4d4d4",
            insertbackground="#ffffff"
        )
        self.logs_text_widget.pack(fill=tk.BOTH, expand=True, padx=5, pady=5)
    
    def add_payout_data(self, payouts: Dict[str, float], categories: Optional[Dict[str, str]] = None):
        """Добавить данные о payout"""
        self.payout_data = []
        
        # Функция определения категории актива
        def get_category(asset_name: str) -> str:
            if categories and asset_name in categories:
                return categories[asset_name]
            
            asset_upper = asset_name.upper()
            if asset_name.startswith('#'):
                return "Акции"
            elif any(fx in asset_upper for fx in ['EUR', 'GBP', 'USD', 'JPY', 'CHF', 'CAD', 'AUD', 'NZD', 'RUB', 'HUF', 'NOK', 'TRY', 'ZAR', 'MXN', 'THB', 'SGD', 'HKD', 'INR', 'CNH', 'KRW', 'ILS', 'AED', 'CNY', 'LBP']):
                return "Валюты"
            elif any(crypto in asset_upper for crypto in ['BTC', 'ETH', 'DASH', 'BCH', 'DOT', 'LNK', 'LTC', 'XRP', 'DOGE', 'ADA', 'SOL']):
                return "Криптовалюты"
            elif any(comm in asset_upper for comm in ['XAU', 'XAG', 'BRENT', 'CRUDE', 'XNG', 'XPT', 'XPD', 'XCU']):
                return "Сырьевые товары"
            elif any(idx in asset_upper for idx in ['SP500', 'SPX', 'NAS', 'DJI', 'JPN', 'D30', 'E50', 'F40', 'E35', '100GBP']):
                return "Индексы"
            else:
                return "Другие"
        
        for asset, payout in payouts.items():
            category = get_category(asset)
            status = "✓ Проходит" if payout >= 80 else "✗ Пропущен"
            self.payout_data.append({
                'category': category,
                'asset': asset,
                'payout': payout,
                'status': status
            })
        self._update_payout_table()
    
    # Метод add_mapping_data удален - вкладка DOM больше не используется
    
    def add_result(self, result: Dict):
        """Добавить результат анализа"""
        self.results_data.append(result)
        self._update_results_table()
    
    def clear_results(self):
        """Очистить все результаты"""
        self.results_data = []
        self._update_results_table()
    
    def add_websocket_assets(self, assets: Dict[str, int], payout_cache: Optional[Dict[str, float]] = None):
        """Добавить данные о WebSocket активах
        
        Args:
            assets: Словарь {asset_name: asset_id} из ASSETS
            payout_cache: Словарь {asset_name: payout} для проверки наличия payout (опционально)
        """
        self.websocket_data = []
        
        # Импортируем функции нормализации из asset_scanner_websocket
        try:
            from asset_scanner_websocket import get_payout_from_cache
            use_advanced_matching = True
        except ImportError:
            # Если импорт не удался, используем простую проверку
            use_advanced_matching = False
            if payout_cache is None:
                payout_cache = {}
        
        if payout_cache is None:
            # Пытаемся получить глобальный payout_cache
            try:
                from asset_scanner_websocket import payout_cache as global_payout_cache
                payout_cache = global_payout_cache
            except ImportError:
                payout_cache = {}
        
        # Функция определения категории актива
        def get_category(asset_name: str) -> str:
            asset_upper = asset_name.upper()
            if asset_name.startswith('#'):
                return "Акции"
            elif any(fx in asset_upper for fx in ['EUR', 'GBP', 'USD', 'JPY', 'CHF', 'CAD', 'AUD', 'NZD', 'RUB', 'HUF', 'NOK', 'TRY', 'ZAR', 'MXN', 'THB', 'SGD', 'HKD', 'INR', 'CNH', 'KRW', 'ILS', 'AED', 'CNY', 'LBP']):
                return "Валюты"
            elif any(crypto in asset_upper for crypto in ['BTC', 'ETH', 'DASH', 'BCH', 'DOT', 'LNK', 'LTC', 'XRP', 'DOGE', 'ADA', 'SOL', 'MATIC', 'LINK', 'UNI', 'AVAX', 'ATOM', 'ALGO', 'XLM', 'EOS', 'TRX', 'XMR', 'ZEC', 'ETC', 'BNB', 'SHIB', 'FIL', 'XTZ', 'VET', 'GRT', 'AAVE', 'MKR', 'COMP', 'YFI', 'SUSHI', 'CRV', '1INCH']):
                return "Криптовалюты"
            elif any(comm in asset_upper for comm in ['XAU', 'XAG', 'BRENT', 'CRUDE', 'XNG', 'XPT', 'XPD', 'XCU', 'GASOLINE', 'HEATINGOIL', 'NATGAS']):
                return "Сырьевые товары"
            elif any(idx in asset_upper for idx in ['SP500', 'SPX', 'NAS', 'DJI', 'JPN', 'D30', 'E50', 'F40', 'E35', '100GBP', 'AUS200', 'CAC', 'AEX', 'SMI', 'H33', 'NIKKEI', 'FTSE', 'HSI', 'SSE', 'ASX', 'TSX', 'IBEX', 'EUROSTOXX']):
                return "Индексы"
            else:
                return "Другие"
        
        # Проверяем, есть ли payout для каждого актива
        # Используем продвинутую функцию сопоставления, если доступна
        def has_payout_in_cache(asset_name: str) -> bool:
            if use_advanced_matching:
                # Используем get_payout_from_cache для правильного сопоставления
                payout = get_payout_from_cache(asset_name, debug=False)
                return payout is not None
            else:
                # Простая проверка (fallback)
                if asset_name in payout_cache:
                    return True
                if asset_name.startswith('#'):
                    if asset_name[1:] in payout_cache:
                        return True
                else:
                    if f'#{asset_name}' in payout_cache:
                        return True
                return False
        
        for asset_name, asset_id in assets.items():
            category = get_category(asset_name)
            has_payout = "✓ Да" if has_payout_in_cache(asset_name) else "✗ Нет"
            
            self.websocket_data.append({
                'asset_id': asset_id,
                'asset_name': asset_name,
                'category': category,
                'has_payout': has_payout
            })
        
        # Сортируем по категории и названию
        self.websocket_data.sort(key=lambda x: (x['category'], x['asset_name']))
        self._update_websocket_table()
    
    def _update_websocket_table(self):
        """Обновить таблицу WebSocket активов"""
        # Очистить таблицу
        for item in self.websocket_tree.get_children():
            self.websocket_tree.delete(item)
        
        # Фильтры
        category = self.websocket_category_filter.get()
        search_text = self.websocket_search_var.get().upper()
        
        # Фильтрация
        filtered = self.websocket_data
        if category != "Все":
            filtered = [d for d in filtered if d['category'] == category]
        
        if search_text:
            filtered = [d for d in filtered if search_text in d['asset_name'].upper()]
        
        # Заполнение
        for data in filtered:
            item = self.websocket_tree.insert("", tk.END, values=(
                data['asset_id'],
                data['asset_name'],
                data['category'],
                data['has_payout']
            ))
        
        # Статистика
        total = len(self.websocket_data)
        shown = len(filtered)
        with_payout = len([d for d in self.websocket_data if d['has_payout'] == "✓ Да"])
        categories_count = {}
        for d in self.websocket_data:
            cat = d['category']
            categories_count[cat] = categories_count.get(cat, 0) + 1
        
        cats_str = ", ".join([f"{k}: {v}" for k, v in sorted(categories_count.items())])
        self.websocket_stats.config(
            text=f"Показано: {shown} из {total} | С payout: {with_payout} | Категории: {cats_str} | Фильтр: {category}"
        )
    
    def add_log(self, message: str, level: str = "INFO"):
        """Добавить лог"""
        timestamp = datetime.now().strftime("%H:%M:%S")
        
        # Цвета по уровням
        colors = {
            "INFO": "#d4d4d4",
            "SUCCESS": "#4ec9b0",
            "WARNING": "#dcdcaa",
            "ERROR": "#f48771",
            "DEBUG": "#569cd6"
        }
        
        color = colors.get(level, "#d4d4d4")
        prefix = {
            "INFO": "",
            "SUCCESS": "✓",
            "WARNING": "⚠",
            "ERROR": "✗",
            "DEBUG": "[DEBUG]"
        }.get(level, "")
        
        self.logs_text_widget.config(state=tk.NORMAL)
        self.logs_text_widget.insert(tk.END, f"[{timestamp}] {prefix} {message}\n")
        
        # Применяем цвет к последней строке
        start_index = self.logs_text_widget.index("end-2l linestart")
        end_index = self.logs_text_widget.index("end-1l lineend")
        self.logs_text_widget.tag_add("colored", start_index, end_index)
        self.logs_text_widget.tag_config("colored", foreground=color)
        
        self.logs_text_widget.see(tk.END)
        self.logs_text_widget.config(state=tk.DISABLED)
        self.root.update_idletasks()
    
    def _update_payout_table(self):
        """Обновить таблицу payout"""
        # Очистить таблицу
        for item in self.payout_tree.get_children():
            self.payout_tree.delete(item)
        
        # Фильтры
        category = self.category_filter.get()
        try:
            min_payout = float(self.min_payout_var.get())
        except:
            min_payout = 0
        
        # Фильтрация и заполнение
        filtered = self.payout_data
        if category != "Все":
            filtered = [d for d in filtered if d['category'] == category]
        filtered = [d for d in filtered if d['payout'] >= min_payout]
        
        # Сортируем по payout (убывание)
        filtered.sort(key=lambda x: x['payout'], reverse=True)
        
        for data in filtered:
            item = self.payout_tree.insert("", tk.END, values=(
                data['category'],
                data['asset'],
                f"{data['payout']:.1f}%",
                data['status']
            ))
            
            # Цветовая подсветка статуса
            if data['status'] == "✓ Проходит":
                self.payout_tree.set(item, "status", "✓ Проходит")
        
        # Статистика
        total = len(self.payout_data)
        shown = len(filtered)
        passed = len([d for d in filtered if d['status'] == "✓ Проходит"])
        self.payout_stats.config(
            text=f"Показано: {shown} из {total} | Проходит фильтр: {passed} | Мин. payout: {min_payout}% | Категория: {category}"
        )
    
    # Метод _update_mapping_table удален - вкладка DOM больше не используется
    
    def _update_results_table(self):
        """Обновить таблицу результатов"""
        # Очистить таблицу
        for item in self.results_tree.get_children():
            self.results_tree.delete(item)
        
        # Фильтр
        filter_action = self.action_filter.get()
        filtered = self.results_data
        if filter_action != "Все":
            filtered = [r for r in filtered if r.get('recommended_action') == filter_action]
        
        # Заполнение
        for result in filtered:
            colors_str = " → ".join([
                "🟢" if c == 'green' else "🔴" if c == 'red' else "⚪"
                for c in result.get('candle_colors', [])
            ])
            action = result.get('recommended_action', 'WAIT')
            if action == 'CALL':
                action_emoji = "🟢 CALL"
            elif action == 'PUT':
                action_emoji = "🔴 PUT"
            else:
                action_emoji = "⚪ WAIT"
            
            payout_str = f"{result.get('payout', 0):.1f}%" if result.get('payout') else "N/A"
            
            item = self.results_tree.insert("", tk.END, values=(
                result.get('asset', ''),
                payout_str,
                action_emoji,
                colors_str,
                "✓ Успешно"
            ))
        
        # Статистика
        total = len(self.results_data)
        calls = len([r for r in self.results_data if r.get('recommended_action') == 'CALL'])
        puts = len([r for r in self.results_data if r.get('recommended_action') == 'PUT'])
        waits = len([r for r in self.results_data if r.get('recommended_action') == 'WAIT'])
        shown = len(filtered)
        self.results_stats.config(
            text=f"Показано: {shown} из {total} | CALL: {calls} | PUT: {puts} | WAIT: {waits} | Фильтр: {filter_action}"
        )
    
    # Метод _export_mapping удален - вкладка DOM больше не используется
    
    def _on_result_double_click(self, event):
        """Обработчик двойного клика на строку в таблице результатов - открывает график свечей"""
        item = self.results_tree.selection()[0] if self.results_tree.selection() else None
        if not item:
            return
        
        # Получаем данные строки
        values = self.results_tree.item(item, 'values')
        if not values:
            return
        
        asset_name = values[0]  # Первая колонка - название актива
        
        # Находим соответствующий результат в данных
        result = None
        for r in self.results_data:
            if r.get('asset') == asset_name:
                result = r
                break
        
        if not result:
            messagebox.showwarning("Предупреждение", f"Данные для актива '{asset_name}' не найдены")
            return
        
        # Проверяем наличие данных свечей
        candles = result.get('candles')
        if not candles or len(candles) < 2:
            messagebox.showwarning("Предупреждение", f"Нет данных свечей для актива '{asset_name}'")
            return
        
        # Открываем окно с графиком
        self._show_candle_chart(asset_name, candles, result)
    
    def _show_candle_chart(self, asset_name: str, candles: List[Any], result: Dict):
        """Отображает график свечей в отдельном окне"""
        global _Figure, _FigureCanvasTkAgg, _Rectangle, _Patch, MATPLOTLIB_AVAILABLE
        
        # Пытаемся импортировать matplotlib при необходимости
        matplotlib_ok = False
        Figure = None
        FigureCanvasTkAgg = None
        Rectangle = None
        Patch = None
        
        # Сначала пробуем через функцию ленивого импорта
        try:
            matplotlib_ok = _import_matplotlib()
            if matplotlib_ok:
                Figure = _Figure
                FigureCanvasTkAgg = _FigureCanvasTkAgg
                Rectangle = _Rectangle
                Patch = _Patch
        except Exception:
            pass
        
        # Если ленивый импорт не сработал, пробуем импортировать напрямую
        if not matplotlib_ok:
            try:
                import matplotlib
                try:
                    matplotlib.use('TkAgg', force=True)
                except:
                    try:
                        matplotlib.use('TkAgg')
                    except:
                        pass
                from matplotlib.figure import Figure as MplFigure
                from matplotlib.backends.backend_tkagg import FigureCanvasTkAgg as MplCanvas
                from matplotlib.patches import Rectangle as MplRectangle, Patch as MplPatch
                
                Figure = MplFigure
                FigureCanvasTkAgg = MplCanvas
                Rectangle = MplRectangle
                Patch = MplPatch
                matplotlib_ok = True
                
                # Сохраняем для дальнейшего использования
                _Figure = Figure
                _FigureCanvasTkAgg = FigureCanvasTkAgg
                _Rectangle = Rectangle
                _Patch = Patch
                MATPLOTLIB_AVAILABLE = True
            except Exception as import_error:
                import sys
                python_path = sys.executable
                error_msg = (
                    f"Не удалось импортировать matplotlib.\n\n"
                    f"Ошибка: {str(import_error)}\n\n"
                    f"Python: {python_path}\n\n"
                    f"Установите matplotlib в это окружение:\n"
                    f'"{python_path}" -m pip install matplotlib\n\n'
                    f"Или через командную строку:\n"
                    f"pip install matplotlib"
                )
                messagebox.showerror("Ошибка", error_msg)
                return
        
        if not matplotlib_ok or Figure is None:
            messagebox.showerror("Ошибка", 
                "matplotlib не установлен. Графики свечей недоступны.\n"
                "Установите matplotlib: pip install matplotlib")
            return
        
        # Создаем новое окно
        chart_window = tk.Toplevel(self.root)
        chart_window.title(f"График свечей - {asset_name}")
        chart_window.geometry("1200x700")
        
        # Создаем фигуру matplotlib
        fig = Figure(figsize=(12, 7), dpi=100)
        ax = fig.add_subplot(111)
        
        # Сортируем свечи по timestamp
        sorted_candles = sorted(candles, key=lambda c: c.timestamp)
        
        # Подготавливаем данные для графика
        opens = [c.open for c in sorted_candles]
        highs = [c.high for c in sorted_candles]
        lows = [c.low for c in sorted_candles]
        closes = [c.close for c in sorted_candles]
        
        # Получаем цвета свечей из анализа (последние 6)
        candle_colors = result.get('candle_colors', [])
        recommended_action = result.get('recommended_action', 'WAIT')
        payout = result.get('payout')
        
        # Рисуем свечи
        width = 0.6
        for i, (open_price, high, low, close) in enumerate(zip(opens, highs, lows, closes)):
            # Определяем цвет свечи
            is_green = close > open_price
            
            # Определяем цвет на основе анализа (для последних 6 свечей)
            color = 'gray'
            if i >= len(sorted_candles) - 6:
                color_idx = i - (len(sorted_candles) - 6)
                if color_idx < len(candle_colors):
                    if candle_colors[color_idx] == 'green':
                        color = 'green'
                    elif candle_colors[color_idx] == 'red':
                        color = 'red'
                    else:
                        color = 'gray'
                else:
                    color = 'green' if is_green else 'red'
            else:
                color = 'green' if is_green else 'red'
            
            # Рисуем тень (wick)
            ax.plot([i, i], [low, high], color='black', linewidth=0.5)
            
            # Рисуем тело свечи
            body_bottom = min(open_price, close)
            body_top = max(open_price, close)
            body_height = body_top - body_bottom
            
            if body_height == 0:
                # Doji - рисуем горизонтальную линию
                ax.plot([i - width/2, i + width/2], [open_price, open_price], color=color, linewidth=1.5)
            else:
                # Обычная свеча
                rect = Rectangle((i - width/2, body_bottom), width, body_height, 
                               facecolor=color, edgecolor='black', linewidth=0.5)
                ax.add_patch(rect)
        
        # Настройка осей
        ax.set_xlabel('Номер свечи', fontsize=10)
        ax.set_ylabel('Цена', fontsize=10)
        title = f'{asset_name} - График свечей'
        if payout:
            title += f' | Payout: {payout:.1f}%'
        title += f' | Рекомендация: {recommended_action}'
        ax.set_title(title, fontsize=12, fontweight='bold')
        ax.grid(True, alpha=0.3)
        
        # Устанавливаем метки на оси X (показываем каждую 5-ю свечу)
        step = max(1, len(sorted_candles) // 20)
        ax.set_xticks(range(0, len(sorted_candles), step))
        ax.set_xticklabels([str(i) for i in range(0, len(sorted_candles), step)], rotation=45)
        
        # Добавляем легенду
        legend_elements = [
            Patch(facecolor='green', edgecolor='black', label='Рост'),
            Patch(facecolor='red', edgecolor='black', label='Падение'),
            Patch(facecolor='gray', edgecolor='black', label='Нейтрально')
        ]
        ax.legend(handles=legend_elements, loc='upper left')
        
        # Встраиваем график в окно tkinter
        canvas = FigureCanvasTkAgg(fig, chart_window)
        canvas.draw()
        canvas.get_tk_widget().pack(fill=tk.BOTH, expand=True)
        
        # Добавляем кнопку закрытия
        close_btn = ttk.Button(chart_window, text="Закрыть", command=chart_window.destroy)
        close_btn.pack(pady=5)


# Глобальный объект GUI (создается из основного скрипта)
gui_instance: Optional[AssetScannerGUI] = None


def init_gui():
    """Инициализировать GUI"""
    global gui_instance
    try:
        root = tk.Tk()
        gui_instance = AssetScannerGUI(root)
        return root
    except Exception as e:
        print(f"⚠ Ошибка при создании GUI: {e}")
        return None


def get_gui():
    """Получить экземпляр GUI"""
    return gui_instance
