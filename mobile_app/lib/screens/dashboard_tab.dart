import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../models/models.dart';
import '../widgets/common.dart';
import 'meter_detail_screen.dart';
import 'notifications_screen.dart';

class DashboardTab extends StatefulWidget {
  const DashboardTab({super.key});
  @override
  State<DashboardTab> createState() => _DashboardTabState();
}

class _DashboardTabState extends State<DashboardTab> {
  List<Meter> _meters = [];
  Map<int, CreditInfo> _credits = {};
  Map<int, double> _weekKwh = {};
  List<AppNotification> _notifications = [];
  int _unread = 0;
  String? _error;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() { _loading = true; _error = null; });
    try {
      final api = SessionStore.api;
      final metersJson = await api.get('/meters') as List;
      _meters = metersJson.map((e) => Meter.fromJson(Map<String, dynamic>.from(e))).toList();

      _credits = {};
      _weekKwh = {};
      for (final m in _meters) {
        try {
          final detail = await api.get('/meters/${m.id}');
          _credits[m.id] = CreditInfo.fromJson(Map<String, dynamic>.from(detail['credit']));
          final cons = await api.get('/meters/${m.id}/consumption?from=${_daysAgo(7)}&to=${_today()}');
          _weekKwh[m.id] = (cons['total_kwh'] is num) ? (cons['total_kwh'] as num).toDouble() : 0;
        } catch (_) { /* per-meter failures should not blank the dashboard */ }
      }

      final notif = await api.get('/notifications?limit=5');
      _notifications = ((notif as List?) ?? []).map((e) => AppNotification.fromJson(Map<String, dynamic>.from(e))).toList();
      _unread = _notifications.where((n) => !n.isRead).length;
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } catch (_) {
      setState(() => _error = 'Could not reach the SEMS server.');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  String _today() => DateTime.now().toUtc().toIso8601String().substring(0, 10);
  String _daysAgo(int n) => DateTime.now().toUtc().subtract(Duration(days: n)).toIso8601String().substring(0, 10);

  @override
  Widget build(BuildContext context) {
    final session = context.watch<SessionStore>();
    return Scaffold(
      appBar: AppBar(
        title: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('Karibu, ${session.userName?.split(' ').first ?? ''}', style: const TextStyle(fontSize: 16)),
          const Text('Smart Electricity Management', style: TextStyle(fontSize: 11, color: Color(0xFF9FB4D8))),
        ]),
        actions: [
          Stack(children: [
            IconButton(icon: const Icon(Icons.notifications_outlined), onPressed: () async {
              await Navigator.push(context, MaterialPageRoute(builder: (_) => const NotificationsScreen()));
              _load();
            }),
            if (_unread > 0) Positioned(right: 7, top: 7, child: Container(
              padding: const EdgeInsets.all(4), decoration: const BoxDecoration(color: kRed, shape: BoxShape.circle),
              child: Text('$_unread', style: const TextStyle(fontSize: 9, color: Colors.white, fontWeight: FontWeight.bold))),
            ),
          ]),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: _loading && _meters.isEmpty
            ? const Center(child: CircularProgressIndicator())
            : _error != null && _meters.isEmpty
                ? ListView(children: [ErrorBox(_error!, onRetry: _load)])
                : ListView(
                    padding: const EdgeInsets.fromLTRB(16, 4, 16, 24),
                    physics: const AlwaysScrollableScrollPhysics(),
                    children: [
                      const SimBanner(),
                      if (_meters.isEmpty) ...[
                        const SizedBox(height: 60),
                        const EmptyState(Icons.bolt, 'No meter is linked to your account yet.\nAn administrator must assign your meter.'),
                      ],
                      for (final m in _meters) _meterCard(m),
                      const SectionTitle('Recent notifications'),
                      if (_notifications.isEmpty) const EmptyState(Icons.notifications_none, 'No notifications yet.'),
                      for (final n in _notifications.take(3)) Card(
                        margin: const EdgeInsets.only(bottom: 8),
                        child: ListTile(
                          leading: Icon(n.isRead ? Icons.notifications_none : Icons.notifications_active, color: n.isRead ? kMuted : kTeal),
                          title: Text(n.title, style: TextStyle(fontSize: 13, fontWeight: n.isRead ? FontWeight.w500 : FontWeight.w800)),
                          subtitle: Text(n.message, maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 12)),
                          trailing: Text(dmy(n.createdAt), style: const TextStyle(fontSize: 10.5, color: kMuted)),
                        ),
                      ),
                    ],
                  ),
      ),
    );
  }

  Widget _meterCard(Meter m) {
    final credit = _credits[m.id];
    final week = _weekKwh[m.id] ?? 0;
    return Card(
      margin: const EdgeInsets.only(top: 12),
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: () async {
          await Navigator.push(context, MaterialPageRoute(builder: (_) => MeterDetailScreen(meterId: m.id)));
          _load();
        },
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Container(width: 40, height: 40, decoration: BoxDecoration(color: kNavy, borderRadius: BorderRadius.circular(11)),
                child: const Icon(Icons.electric_meter, color: Colors.white, size: 22)),
              const SizedBox(width: 10),
              Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(m.meterNumber, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
                Text(m.serviceLocation, style: const TextStyle(fontSize: 12, color: kMuted)),
              ])),
              StatusChip(m.integrationStatus, label: m.statusLabel),
            ]),
            const SizedBox(height: 14),
            Row(children: [
              Expanded(child: _miniStat('Est. credit', credit != null ? tzs(credit.credit) : '—',
                  caption: 'demo tariff', color: (credit != null && credit.credit < 2000) ? kRed : kGreen)),
              const SizedBox(width: 10),
              Expanded(child: _miniStat('Last 7 days', kwh(week), caption: 'simulated readings')),
              const SizedBox(width: 10),
              Expanded(child: _miniStat('Units used', credit != null ? kwh(credit.units) : '—', caption: 'all time')),
            ]),
          ]),
        ),
      ),
    );
  }

  Widget _miniStat(String label, String value, {String? caption, Color color = kNavy}) {
    return Container(
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(color: const Color(0xFFF8FAFD), borderRadius: BorderRadius.circular(11), border: Border.all(color: kLine)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(label, style: const TextStyle(fontSize: 10.5, color: kMuted)),
        const SizedBox(height: 2),
        Text(value, style: TextStyle(fontWeight: FontWeight.w800, fontSize: 12.5, color: color)),
        if (caption != null) Text(caption, style: const TextStyle(fontSize: 9.5, color: kMuted)),
      ]),
    );
  }
}
