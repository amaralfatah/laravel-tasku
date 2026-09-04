import { index as projectsIndex } from '@/routes/projects';
import type { BreadcrumbItem } from '@/types/navigation';
import type { OrgUnitLocation } from '@/types/projects';

/**
 * Breadcrumbs for any view of a project, ending on the project itself.
 *
 * Between the project list and the project sit the org units it belongs to —
 * `Project › Divisi IT › Subdivisi Pengembangan › Unit Cyber › Migrasi ERP` —
 * so somebody looking at a task can tell whose work it is without opening the
 * organisation page. Those crumbs carry no link: shaping the org tree is the
 * platform operator's screen, not the customer's.
 */
export function projectCrumbs(
    project: { name: string; org_unit: OrgUnitLocation },
    href: BreadcrumbItem['href'],
): BreadcrumbItem[] {
    return [
        { title: 'Project', href: projectsIndex() },
        ...project.org_unit.trail.map((name) => ({ title: name })),
        { title: project.org_unit.name },
        { title: project.name, href },
    ];
}
