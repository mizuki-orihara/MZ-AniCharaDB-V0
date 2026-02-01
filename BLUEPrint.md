/animdb/ (Root)

│
├── index.html          # 全体ポータル
├── menu.html           # 管理ダッシュボード（全プロセスの監視）
├── merge_confirm.html  # マージ作業専用UI
│
├── API/                # 【心臓部：システム統括】
│   ├── task_master.json # 信号機（ゲート開閉・ロック・状況）
│   └── status_api.php  # menu.html と task_master を仲介する窓口

├── import/             # 【玄関：フロントエンド＆ロジック】
│   ├── uploader.html   # アップロード画面
│   ├── receiver.php    # 受信担当（^tempへ）
│   ├── processor.php   # 検品担当（バリデーション）
│   ├── dispatcher.php  # 司令塔（MainDB照合・仕分け）
│   ├── api.php         # 状況報告（各jsonを集約してmenuへ送る）
│   └── (config.php)    # パス設定の共通定義（推奨）
│
├── import_cache/       # 【作業場：システム一時領域】
│   ├── ^temp[SID]/     # セッションごとの一時展開
│   ├── registry.json   # 進行中セッション管理
│   └── validation_errors/ # 検品落ちしたカード
│
├── MergeStandby/       # 【待機：要目視確認】
│   └── pending/        # 解決を保留したカード
│
├── MainDB/             # 【本番：完成データ群】
│   ├── RegisterCache/  # 【予約：新規登録確定済み・ID払い出し待ち】
│   ├── DBindex.json    # 本番ファイルリスト
│   ├── indexer.php     # 索引再構築スクリプト
│   └── [A-Z0-9]...     # 本番データ（正式命名済みJSON）
│
└── archive/            # 【書庫：履歴管理】
    └── replaced/       # マージで上書きされた旧データ
