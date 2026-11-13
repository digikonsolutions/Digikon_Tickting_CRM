import { isAgencyRole } from '../auth/roles'

// Visible wording that differs for agency (client-facing) roles. Routes, API names and database
// terms stay the same: "employee" is still the internal term.
export function getAgencyLabels(role) {
  const isAgency = isAgencyRole(role)
  return {
    employeesHeld: isAgency ? 'My specialists' : 'Employees held',
    myEmployees: isAgency ? 'My specialists' : 'My employees',
    holdEmployee: isAgency ? 'Add specialist' : 'Hold employee',
    selectEmployee: isAgency ? 'Choose specialist' : 'Select employee',
    endAssignment: isAgency ? 'Release specialist' : 'End assignment',
    dueDate: isAgency ? 'Delivery date' : 'Due date',
    specialist: isAgency ? 'Specialist' : 'Employee',
    specialists: isAgency ? 'Specialists' : 'Employees',
  }
}
