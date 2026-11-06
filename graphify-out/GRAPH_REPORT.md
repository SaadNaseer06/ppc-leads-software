# Graph Report - ppc-leads-software  (2026-11-06)

## Corpus Check
- cluster-only mode — file stats not available

## Summary
- 1461 nodes · 3645 edges · 106 communities (55 shown, 51 thin omitted)
- Extraction: 94% EXTRACTED · 6% INFERRED · 0% AMBIGUOUS · INFERRED: 208 edges (avg confidence: 0.85)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `cc5f2d6b`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- Community 0
- Community 1
- Community 2
- Community 3
- Community 4
- Community 5
- Community 6
- Community 7
- Community 8
- Community 9
- Community 10
- Community 11
- Community 12
- Community 13
- Community 14
- Community 15
- Community 16
- Community 17
- Community 18
- Community 19
- Community 20
- Community 21
- Community 22
- Community 23
- Community 24
- Community 25
- Community 26
- Community 27
- Community 28
- Community 29
- Community 30
- Community 31
- Community 32
- Community 33
- Community 34
- Community 35
- Community 36
- Community 37
- Community 38
- Community 39
- Community 40
- Community 41
- Community 42
- Community 43
- Community 44
- Community 45
- Community 47
- Community 48
- Community 49
- Community 50
- Community 51
- Community 52
- Community 53
- Community 54
- Community 55
- Community 57
- Community 58
- Community 59
- Community 60
- Community 62
- Community 63
- Community 64
- Community 65
- Community 66
- Community 67
- Community 68
- Community 69
- Community 70
- Community 71
- Community 72
- Community 73
- Community 74
- Community 75
- Community 76
- Community 77
- Community 78
- Community 79
- Community 82
- Community 83
- Community 84
- Community 85
- Community 86
- Community 87
- Community 89
- Community 90
- Community 91
- Community 92
- Community 93
- Community 94

## God Nodes (most connected - your core abstractions)
1. `User` - 93 edges
2. `Lead` - 61 edges
3. `Role` - 50 edges
4. `Website` - 49 edges
5. `Conversation` - 44 edges
6. `apiRequest()` - 40 edges
7. `Invoice` - 36 edges
8. `useToast()` - 34 edges
9. `lucide-react` - 32 edges
10. `Controller` - 31 edges

## Surprising Connections (you probably didn't know these)
- `SalesReportTest` --references--> `Website`  [EXTRACTED]
  tests/Feature/SalesReportTest.php → app/Models/Website.php
- `SalesReportTest` --references--> `Brand`  [EXTRACTED]
  tests/Feature/SalesReportTest.php → app/Models/Brand.php
- `SalesReportTest` --references--> `User`  [EXTRACTED]
  tests/Feature/SalesReportTest.php → app/Models/User.php
- `{closure#1}()` --references--> `Role`  [EXTRACTED]
  database/seeders/DatabaseSeeder.php → app/Enums/Role.php
- `{closure#1}()` --references--> `Brand`  [EXTRACTED]
  tests/TestCase.php → app/Models/Brand.php

## Import Cycles
- None detected.

## Communities (106 total, 51 thin omitted)

### Community 0 - "Community 0"
Cohesion: 0.05
Nodes (19): {closure#1}(), {closure#1}(), {closure#2}(), {closure#3}(), Conversation, Website, {closure#2}(), {closure#3}() (+11 more)

### Community 1 - "Community 1"
Cohesion: 0.06
Nodes (8): ChatDeleted, ChatMessageSent, ChatTransferred, LeadsChanged, {closure#1}(), {closure#1}(), {closure#5}(), SalesReportTest

### Community 2 - "Community 2"
Cohesion: 0.11
Nodes (34): lucide-react, react, ConfirmContext, ConfirmFn, ConfirmOptions, Pending, useConfirm(), Modal() (+26 more)

### Community 3 - "Community 3"
Cohesion: 0.07
Nodes (40): dependencies, @fontsource-variable/geist@^5.3.0, @fontsource-variable/geist-mono@^5.3.0, @inertiajs/react@^3.9.1, laravel-echo@^2.6.3, lucide-react@^1.62.0, pusher-js@^8.6.0, react@^19.3.0 (+32 more)

### Community 4 - "Community 4"
Cohesion: 0.11
Nodes (32): useLiveSync(), CHANNEL_STYLES, ChannelBadge(), SourceBadge(), STATUS_STYLES, StatusBadge(), CopyButton(), Assignee (+24 more)

### Community 5 - "Community 5"
Cohesion: 0.08
Nodes (34): compact(), niceMax(), Point, SalesTrendChart(), Swatch(), BrandReport, BrandsData, DashboardData (+26 more)

### Community 6 - "Community 6"
Cohesion: 0.16
Nodes (35): api(), boot(), buildUI(), chatGone(), closePanel(), contactSkipped(), css(), el() (+27 more)

### Community 7 - "Community 7"
Cohesion: 0.10
Nodes (27): @inertiajs/react, PageModule, pages, setup(), leadChannels(), LiveContext, LiveProvider(), LiveValue (+19 more)

### Community 8 - "Community 8"
Cohesion: 0.09
Nodes (31): apiRequest(), ChatMessage, ChatsData, ConversationRow, FrontSalesRep, BrandsIndex(), AutoAssignToggle(), toggle() (+23 more)

### Community 9 - "Community 9"
Cohesion: 0.12
Nodes (7): AuthController, ChatController, {closure#1}(), DashboardController, LeadController, Dashboard, TeamDirectory

### Community 10 - "Community 10"
Cohesion: 0.11
Nodes (8): BrandController, WebsiteController, AppServiceProvider, {closure#1}(), {closure#2}(), {closure#3}(), {closure#5}(), {closure#6}()

### Community 11 - "Community 11"
Cohesion: 0.16
Nodes (26): useLiveReload(), Avatar(), colorFor(), COLORS, initials(), PageHeader(), DateLike, fmt() (+18 more)

### Community 12 - "Community 12"
Cohesion: 0.07
Nodes (27): concurrency, cancel-in-progress, group, name, runs-on, steps, timeout-minutes, CPANEL_APP_DIR (+19 more)

### Community 13 - "Community 13"
Cohesion: 0.12
Nodes (4): InvoiceController, EnsureUserIsActive, InvoicePayments, InvoicePdf

### Community 14 - "Community 14"
Cohesion: 0.09
Nodes (7): {closure#1}(), {closure#2}(), {closure#3}(), {closure#10}(), {closure#7}(), {closure#8}(), {closure#9}()

### Community 16 - "Community 16"
Cohesion: 0.15
Nodes (5): IntakeController, StripeWebhookController, TawkWebhookController, SessionController, Controller

### Community 18 - "Community 18"
Cohesion: 0.17
Nodes (4): {closure#1}(), {closure#2}(), {closure#3}(), PageController

### Community 19 - "Community 19"
Cohesion: 0.12
Nodes (9): AssignmentException, {closure#1}(), {closure#10}(), {closure#2}(), {closure#5}(), {closure#6}(), {closure#7}(), {closure#8}() (+1 more)

### Community 20 - "Community 20"
Cohesion: 0.12
Nodes (12): Role, Admin, BrandManager, ChatAgent, Developer, Finance, FrontSales, SuperAdmin (+4 more)

### Community 21 - "Community 21"
Cohesion: 0.11
Nodes (4): {closure#1}(), {closure#3}(), LeadActivity, PaymentTransaction

### Community 22 - "Community 22"
Cohesion: 0.14
Nodes (5): {closure#1}(), {closure#6}(), Brand, {closure#1}(), TestCase

### Community 23 - "Community 23"
Cohesion: 0.20
Nodes (3): LeadAssignment, LeadDeletion, LiveSignal

### Community 25 - "Community 25"
Cohesion: 0.29
Nodes (14): EmptyState(), formatCalendarDay(), formatDate(), formatMoney(), FinanceIndex(), Invoices(), balanceDue(), InvoiceActions() (+6 more)

### Community 26 - "Community 26"
Cohesion: 0.15
Nodes (7): Lead, {closure#1}(), {closure#13}(), {closure#2}(), {closure#4}(), {closure#5}(), {closure#9}()

### Community 27 - "Community 27"
Cohesion: 0.17
Nodes (4): ActivityLog, ConversationMessage, DeletedLead, WebhookEvent

### Community 31 - "Community 31"
Cohesion: 0.13
Nodes (3): {closure#7}(), {closure#1}(), {closure#4}()

### Community 32 - "Community 32"
Cohesion: 0.13
Nodes (6): {closure#10}(), {closure#6}(), {closure#7}(), {closure#8}(), {closure#9}(), LeadRowResource

### Community 33 - "Community 33"
Cohesion: 0.13
Nodes (7): LeadStatus, Contacted, Converted, Lost, New, Qualified, Spam

### Community 35 - "Community 35"
Cohesion: 0.21
Nodes (8): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}()

### Community 36 - "Community 36"
Cohesion: 0.13
Nodes (14): compilerOptions, isolatedModules, jsx, lib, module, moduleResolution, noEmit, paths (+6 more)

### Community 37 - "Community 37"
Cohesion: 0.14
Nodes (13): MYSQL_DATABASE, MYSQL_ROOT_PASSWORD, command, container_name, environment, image, ports, restart (+5 more)

### Community 38 - "Community 38"
Cohesion: 0.27
Nodes (3): {closure#11}(), {closure#4}(), LeadQuery

### Community 39 - "Community 39"
Cohesion: 0.23
Nodes (10): Created, CreateInvoiceDialog(), InvoiceForm(), onSubmit(), InvoiceReady(), Item, LeadOption, LeadPicker() (+2 more)

### Community 40 - "Community 40"
Cohesion: 0.18
Nodes (6): LeadSource, Chat, Form, Manual, Offline, {closure#1}()

### Community 44 - "Community 44"
Cohesion: 0.25
Nodes (5): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), DashboardPagesTest

### Community 45 - "Community 45"
Cohesion: 0.20
Nodes (7): Channel, Direct, Organic, Ppc, Referral, Social, Unknown

### Community 51 - "Community 51"
Cohesion: 0.22
Nodes (4): {closure#1}(), {closure#3}(), {closure#6}(), {closure#8}()

### Community 52 - "Community 52"
Cohesion: 0.22
Nodes (8): description, keywords, license, minimum-stability, name, prefer-stable, $schema, type

### Community 53 - "Community 53"
Cohesion: 0.22
Nodes (9): scripts, dev, post-autoload-dump, post-create-project-cmd, post-root-package-install, post-update-cmd, pre-package-uninstall, setup (+1 more)

### Community 58 - "Community 58"
Cohesion: 0.31
Nodes (4): attribution(), capture(), log(), send()

### Community 62 - "Community 62"
Cohesion: 0.25
Nodes (8): require, barryvdh/laravel-dompdf, inertiajs/inertia-laravel, laravel/framework, laravel/sanctum, laravel/tinker, php, pusher/pusher-php-server

### Community 63 - "Community 63"
Cohesion: 0.25
Nodes (8): require-dev, fakerphp/faker, laravel/pail, laravel/pint, laravel/sail, mockery/mockery, nunomaduro/collision, phpunit/phpunit

### Community 68 - "Community 68"
Cohesion: 0.29
Nodes (7): pestphp/pest-plugin, php-http/discovery, config, allow-plugins, optimize-autoloader, preferred-install, sort-packages

### Community 69 - "Community 69"
Cohesion: 0.43
Nodes (4): BrandMark(), DEMO_ACCOUNTS, Feature(), Login()

### Community 71 - "Community 71"
Cohesion: 0.40
Nodes (3): {closure#1}(), {closure#2}(), {closure#1}()

### Community 72 - "Community 72"
Cohesion: 0.33
Nodes (3): {closure#1}(), {closure#2}(), {closure#3}()

### Community 73 - "Community 73"
Cohesion: 0.33
Nodes (3): {closure#1}(), {closure#2}(), {closure#3}()

### Community 74 - "Community 74"
Cohesion: 0.33
Nodes (3): {closure#1}(), {closure#2}(), {closure#3}()

### Community 75 - "Community 75"
Cohesion: 0.33
Nodes (3): {closure#1}(), {closure#2}(), {closure#3}()

### Community 76 - "Community 76"
Cohesion: 0.33
Nodes (3): {closure#1}(), {closure#2}(), {closure#3}()

### Community 79 - "Community 79"
Cohesion: 0.40
Nodes (5): autoload, psr-4, App\\, Database\\Factories\\, Database\\Seeders\\

### Community 86 - "Community 86"
Cohesion: 0.60
Nodes (4): compact(), LeadsTrendChart(), niceMax(), Point

### Community 87 - "Community 87"
Cohesion: 0.67
Nodes (4): LeadFiltersBar(), navigate(), onSearch(), Select()

### Community 89 - "Community 89"
Cohesion: 0.67
Nodes (3): autoload-dev, psr-4, Tests\\

### Community 90 - "Community 90"
Cohesion: 0.67
Nodes (3): extra, laravel, dont-discover

## Knowledge Gaps
- **26 isolated node(s):** `Admin`, `BrandManager`, `ChatAgent`, `Developer`, `Finance` (+21 more)
  These have ≤1 connection - possible missing edges. (Counts symbols only; 435 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **51 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `User` connect `Community 15` to `Community 0`, `Community 1`, `Community 9`, `Community 10`, `Community 14`, `Community 16`, `Community 20`, `Community 21`, `Community 22`, `Community 23`, `Community 26`, `Community 28`, `Community 29`, `Community 31`, `Community 32`, `Community 33`, `Community 34`, `Community 38`, `Community 42`, `Community 46`, `Community 47`, `Community 50`, `Community 51`, `Community 60`, `Community 61`, `Community 64`, `Community 78`?**
  _High betweenness centrality (0.094) - this node is a cross-community bridge._
- **What connects `Admin`, `BrandManager`, `ChatAgent` to the rest of the system?**
  _26 weakly-connected nodes found - possible missing edges._
- **Should `Community 0` be split into smaller, more focused modules?**
  _Cohesion score 0.05350877192982456 - nodes in this community are weakly interconnected._
- **Why does `Lead` connect `Community 26` to `Community 0`, `Community 9`, `Community 13`, `Community 14`, `Community 21`, `Community 22`, `Community 23`, `Community 24`, `Community 27`, `Community 28`, `Community 30`, `Community 31`, `Community 32`, `Community 33`, `Community 38`, `Community 40`, `Community 42`, `Community 43`, `Community 44`, `Community 50`, `Community 51`, `Community 57`, `Community 59`, `Community 61`, `Community 65`, `Community 66`, `Community 67`, `Community 78`?**
  _High betweenness centrality (0.054) - this node is a cross-community bridge._
- **Should `Community 1` be split into smaller, more focused modules?**
  _Cohesion score 0.0611764705882353 - nodes in this community are weakly interconnected._
- **Why does `Website` connect `Community 0` to `Community 1`, `Community 9`, `Community 14`, `Community 16`, `Community 21`, `Community 22`, `Community 27`, `Community 28`, `Community 29`, `Community 31`, `Community 32`, `Community 33`, `Community 40`, `Community 41`, `Community 57`, `Community 59`, `Community 60`, `Community 61`, `Community 66`, `Community 67`?**
  _High betweenness centrality (0.054) - this node is a cross-community bridge._
- **Should `Community 2` be split into smaller, more focused modules?**
  _Cohesion score 0.1147086031452359 - nodes in this community are weakly interconnected._