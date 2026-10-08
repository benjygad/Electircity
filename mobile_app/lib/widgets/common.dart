import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../core/theme.dart';

String tzs(num v) => 'TZS ${NumberFormat('#,##0').format(v)}';
String kwh(num v) => '${NumberFormat('#,##0.0').format(v)} kWh';
String dmy(DateTime d) => DateFormat('dd MMM yyyy').format(d.toLocal());
String dmyTime(DateTime d) => DateFormat('dd MMM yyyy, HH:mm').format(d.toLocal());

Color statusColor(String s) => switch (s) {
      'resolved' || 'recorded' || 'connected' || 'closed' => kGreen,
      'under_review' || 'in_progress' || 'assigned' || 'pending' || 'stale' || 'warning' => kAmber,
      'failed' || 'offline' || 'critical' || 'integration_unavailable' => kRed,
      'simulated' || 'submitted' || 'fresh' || 'active' => kTeal,
      _ => kMuted,
    };

class StatusChip extends StatelessWidget {
  const StatusChip(this.value, {super.key, this.label});
  final String value;
  final String? label;
  @override
  Widget build(BuildContext context) {
    final c = statusColor(value);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(color: c.withOpacity(.12), borderRadius: BorderRadius.circular(99)),
      child: Text(
        (label ?? value).replaceAll('_', ' ').toUpperCase(),
        style: TextStyle(color: c, fontSize: 10.5, fontWeight: FontWeight.w800, letterSpacing: .4),
      ),
    );
  }
}

class SimBanner extends StatelessWidget {
  const SimBanner({super.key, this.text = 'Simulated data — academic prototype. Not live TANESCO/LUKU data.'});
  final String text;
  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      margin: const EdgeInsets.fromLTRB(16, 10, 16, 2),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(color: const Color(0xFFE0F5F8), borderRadius: BorderRadius.circular(10)),
      child: Row(children: [
        const Icon(Icons.info_outline, size: 16, color: Color(0xFF0B6470)),
        const SizedBox(width: 8),
        Expanded(child: Text(text, style: const TextStyle(fontSize: 11.5, color: Color(0xFF0B6470)))),
      ]),
    );
  }
}

class EmptyState extends StatelessWidget {
  const EmptyState(this.icon, this.message, {super.key});
  final IconData icon;
  final String message;
  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Icon(icon, size: 44, color: kMuted.withOpacity(.6)),
          const SizedBox(height: 10),
          Text(message, textAlign: TextAlign.center, style: const TextStyle(color: kMuted)),
        ]),
      ),
    );
  }
}

class ErrorBox extends StatelessWidget {
  const ErrorBox(this.message, {super.key, this.onRetry});
  final String message;
  final VoidCallback? onRetry;
  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.all(16),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(color: kRed.withOpacity(.07), borderRadius: BorderRadius.circular(12), border: Border.all(color: kRed.withOpacity(.3))),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          const Icon(Icons.error_outline, color: kRed, size: 18),
          const SizedBox(width: 8),
          Expanded(child: Text(message, style: const TextStyle(color: kRed, fontSize: 13))),
        ]),
        if (onRetry != null) ...[
          const SizedBox(height: 10),
          SizedBox(height: 36, child: OutlinedButton.icon(onPressed: onRetry, icon: const Icon(Icons.refresh, size: 16), label: const Text('Retry'))),
        ],
      ]),
    );
  }
}

class StatCard extends StatelessWidget {
  const StatCard({super.key, required this.icon, required this.label, required this.value, this.color = kTeal, this.caption});
  final IconData icon;
  final String label;
  final String value;
  final Color color;
  final String? caption;
  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Row(children: [
          Container(width: 42, height: 42, decoration: BoxDecoration(color: color.withOpacity(.13), borderRadius: BorderRadius.circular(11)), child: Icon(icon, color: color, size: 22)),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(value, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
              Text(label, style: const TextStyle(fontSize: 11.5, color: kMuted)),
              if (caption != null) Text(caption!, style: const TextStyle(fontSize: 10.5, color: kMuted)),
            ]),
          ),
        ]),
      ),
    );
  }
}

class SectionTitle extends StatelessWidget {
  const SectionTitle(this.text, {super.key, this.action, this.onAction});
  final String text;
  final String? action;
  final VoidCallback? onAction;
  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(2, 18, 2, 8),
      child: Row(children: [
        Expanded(child: Text(text, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15.5))),
        if (action != null)
          TextButton(onPressed: onAction, child: Text(action!, style: const TextStyle(color: kTeal, fontWeight: FontWeight.w700))),
      ]),
    );
  }
}
